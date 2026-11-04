<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AgrupamentoDoLoteOutput;
use App\Inteligencia\DTO\ArvoreDoLote;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AgrupamentoDoLoteFalhouException;
use App\Inteligencia\Exception\CaminhosDoLoteInvalidosException;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Prompt\PromptDoAgrupamentoDoLote;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\InterpretadorDoAgrupamentoDoLote;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Sugerir agrupamento com IA" do Importar pastas em lote (spec importar-pastas-em-lote, etapa 2). Síncrono, sem fila:
 * a pessoa clicou e espera a sugestão na mesma tela.
 *
 * Ordem: disponibilidade (plataforma, escritório, módulo, cota) → árvore de nomes (mascarada, neutralizada, teto de
 * 2.000 linhas) → linha da análise gravada ANTES do provedor (conta na cota mesmo se falhar) → provedor (Haiku fixo)
 * → interpretação com as regras de "pasta = caso".
 *
 * A IA nunca grava pasta: só devolve a sugestão. Nada da árvore nem da resposta fica no banco ou no log: a linha da
 * análise guarda o hash da lista enviada, as contagens e um resumo sem nomes; `texto_bruto` fica vazio.
 */
final class SugerirAgrupamentoDoLoteUseCase
{
    public function __construct(
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly MascaradorDeDadosPessoais $mascarador,
        private readonly PromptDoAgrupamentoDoLote $prompt,
        private readonly InterpretadorDoAgrupamentoDoLote $interpretador,
        #[Autowire(service: 'monolog.logger.inteligencia')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $caminhos caminhos relativos dos arquivos, separados por '/'
     *
     * @throws InteligenciaIndisponivelException a IA não está ao alcance de quem pediu (409)
     * @throws CaminhosDoLoteInvalidosException  lista vazia, grande demais ou com caminho inválido (422)
     * @throws AgrupamentoDoLoteFalhouException  o provedor não sugeriu; a análise já foi gravada (502)
     */
    public function executar(User $user, Tenant $tenant, array $caminhos): AgrupamentoDoLoteOutput
    {
        $disponibilidade = $this->disponibilidade->para($user, $tenant);
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }

        $arvore = ArvoreDoLote::montar($caminhos, $this->mascarador);
        $pedido = $this->prompt->montar($arvore);

        $analise = $this->novaAnalise($user, $tenant, $arvore);
        // Gravada antes do provedor: o clique conta na cota mesmo que a chamada falhe.
        $this->analises->salvar($analise, true);

        [$resposta, $resultado] = $this->perguntar($analise, $pedido, $arvore);
        $this->concluir($analise, $resposta, $resultado, $arvore);

        return $resultado->comEnvio($arvore->truncado, $arvore->enviados());
    }

    /** A linha da análise, já em processamento: só o hash da lista enviada e contagens, nunca um nome. */
    private function novaAnalise(User $user, Tenant $tenant, ArvoreDoLote $arvore): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $user,
            tipo: TipoDeAnalise::AgrupamentoDoLote,
            alvoTipo: AnaliseDeInteligencia::ALVO_IMPORTACAO_LOTE,
            alvoId: 0,
            versaoDoPrompt: PromptDoAgrupamentoDoLote::VERSAO,
            contextoHash: hash('sha256', $arvore->texto()),
            contextoResumo: [
                'caminhos' => $arvore->totalDeCaminhos,
                'linhas' => $arvore->enviados(),
                'pastas' => $arvore->totalDePastas,
                'truncado' => $arvore->truncado,
            ],
        );
        $analise->iniciarProcessamento();
        $analise->registrarProvedor($this->provedor->nome(), PromptDoAgrupamentoDoLote::MODELO->value);

        return $analise;
    }

    /**
     * @return array{0: RespostaDeLinguagem, 1: AgrupamentoDoLoteOutput}
     *
     * @throws AgrupamentoDoLoteFalhouException com a análise já marcada e gravada
     */
    private function perguntar(AnaliseDeInteligencia $analise, PedidoDeLinguagem $pedido, ArvoreDoLote $arvore): array
    {
        try {
            $resposta = $this->provedor->completar($pedido);

            return [$resposta, $this->interpretador->interpretar($resposta->texto, $arvore)];
        } catch (ProvedorIndisponivelException $e) {
            $analise->marcarIndisponivel($e->getMessage());

            throw $this->falhou($analise, $e);
        } catch (FalhaDoProvedorException $e) {
            $analise->falhar($e->getMessage());

            throw $this->falhou($analise, $e);
        } catch (RespostaInvalidaException $e) {
            $analise->falhar('resposta inválida do modelo');

            throw $this->falhou($analise, $e);
        } catch (\Throwable $e) {
            // Fora do contrato da porta: a linha não fica em `processando` para sempre. Só a classe
            // vai ao motivo (a mensagem pode trazer texto do pedido); o erro original segue para o log.
            $analise->falhar('erro inesperado: ' . $e::class);
            $this->falhou($analise, $e);

            throw $e;
        }
    }

    private function concluir(AnaliseDeInteligencia $analise, RespostaDeLinguagem $resposta, AgrupamentoDoLoteOutput $resultado, ArvoreDoLote $arvore): void
    {
        $casos = count($resultado->casos);
        $analise->concluir(
            resumo: $casos === 1 ? '1 caso sugerido' : $casos . ' casos sugeridos',
            pontos: [],
            quemAge: null,
            // A resposta traz nomes de cliente e de arquivo do computador de quem pediu: não é guardada.
            textoBruto: '',
            provedor: $this->provedor->nome(),
            modelo: $resposta->modelo,
            tokensEntrada: $resposta->tokensEntrada,
            tokensSaida: $resposta->tokensSaida,
            duracaoMs: $resposta->duracaoMs,
        );
        $this->analises->salvar($analise, true);

        $this->logger->info('Agrupamento do lote sugerido.', [
            'analiseId' => $analise->getId(),
            'tenantId' => $analise->getTenant()?->getId(),
            'linhas' => $arvore->enviados(),
            'truncado' => $arvore->truncado,
            'casos' => $casos,
            'tokensEntrada' => $resposta->tokensEntrada,
            'tokensSaida' => $resposta->tokensSaida,
            'duracaoMs' => $resposta->duracaoMs,
        ]);
    }

    private function falhou(AnaliseDeInteligencia $analise, \Throwable $erro): AgrupamentoDoLoteFalhouException
    {
        $this->analises->salvar($analise, true);
        $this->logger->warning('Agrupamento do lote não sugerido.', [
            'analiseId' => $analise->getId(),
            'tenantId' => $analise->getTenant()?->getId(),
            'status' => $analise->getStatus()->value,
            'erro' => $erro::class,
        ]);

        return new AgrupamentoDoLoteFalhouException($erro);
    }
}
