<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Service\PastaDoContextoDaIa;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * `documentos_e_pecas` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 12): grava documentos, subpastas, lixeira, peças,
 * modelos de peça e o checklist de documentação de uma pasta, pelos UseCases que a tela usa. A entrada é plana: `acao` é
 * a chave da {@see TabelaDeAbaEChave} (a linha da aba e da chave), `operacao` diz o que fazer dentro dela e os campos
 * variam por operação ({@see DocumentosEPecasAcoes}). Os grupos de ações moram em classes próprias
 * ({@see DocumentosEPecasNosDocumentos}, {@see DocumentosEPecasNasPecas}, {@see DocumentosEPecasNoChecklist}).
 *
 * Direto: mover, copiar, reordenar, favoritar, criar subpasta, restaurar da lixeira, escrever peça, usar modelo, destino da
 * peça, salvar modelo novo, favoritar modelo, adicionar, marcar e reordenar item, ligar ou desligar o checklist, salvar e
 * aplicar modelo de checklist. Por proposta (6.7; só a `confirmar_acao` grava): exclusões (documentos, subpasta, excluir de
 * vez, item de checklist, modelo de checklist, modelo de peça) e gravar por cima (editar documento, editar peça, renomear
 * subpasta, salvar modelo existente, editar item, renomear modelo de checklist). Até propor, a ferramenta só LÊ.
 *
 * Ordem das recusas: escopo de escrita → entrada → a guarda sem a pasta (chave do Master e módulo) → a pasta por id e
 * escritório (lixeira ou alheia: "não encontrado") → a guarda com a pasta (acesso, cadeado e aba) →
 * {@see PastaDoContextoDaIa::liberar()} (segredo de justiça) → o recurso `pasta` de editar, em TODA ação → o que a
 * operação toca, achado por id DENTRO da pasta. Na confirmação, o mesmo caminho roda de novo, sem gravar
 * ({@see reconferir()}), e só então grava ({@see executarConfirmada()}).
 */
final class DocumentosEPecasFerramenta implements FerramentaDoConector, ExecutorDeProposta
{
    /**
     * As duas interfaces declaram `TAG`; sem redeclarar o PHP recusa a classe (constante ambígua). O registro segue
     * pelos AutoconfigureTag das interfaces.
     */
    public const TAG = FerramentaDoConector::TAG;

    public const NOME = 'documentos_e_pecas';

    public const MOTIVO_TEXTO_GRANDE = 'texto_grande_demais';
    public const TEXTO_GRANDE = 'O texto é grande demais para ser confirmado de uma vez. Envie um texto menor.';

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly PastaRepository $pastas,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly PropostasDoConector $propostas,
        private readonly DocumentosEPecasNosDocumentos $documentos,
        private readonly DocumentosEPecasNasPecas $pecas,
        private readonly DocumentosEPecasNoChecklist $checklist,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function ferramenta(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Documentos e peças';
    }

    public function descricao(): string
    {
        return DocumentosEPecasEsquema::descricao();
    }

    public function esquemaDeEntrada(): array
    {
        return DocumentosEPecasEsquema::esquema();
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        // Segunda camada: a `ScopePolicy` já deu 403 antes do SDK a quem não tem `mcp:escrita` (5.1).
        if (!$chamada->temEscopo(ServidorDeAutorizacao::ESCOPO_ESCRITA)) {
            throw ErroDaFerramenta::semPermissao();
        }
        $pedido = DocumentosEPecasPedido::dosArgumentos($argumentos);
        $contexto = $this->contextoDa($pedido, $chamada);
        $grupo = $this->grupoDe($pedido->chave);
        $achados = $grupo->conferir($pedido->acao(), $pedido->campos, $contexto);

        if ($pedido->porProposta()) {
            return $this->propor($pedido, $grupo, $achados, $contexto, $chamada);
        }
        $resultado = $grupo->gravar($pedido->acao(), $pedido->campos, $achados, $contexto);

        return new RespostaDaFerramenta(
            ['estado' => 'gravado', 'acao' => $pedido->chave, 'operacao' => $pedido->operacao, 'pasta_id' => $pedido->pastaId, 'resultado' => $resultado->dados],
            contagens: $resultado->contagens,
            ids: $resultado->ids,
        );
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->prepararConfirmada($acao, $dados, $chamada);
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        [$pedido, $grupo, $achados, $contexto] = $this->prepararConfirmada($acao, $dados, $chamada);

        return $grupo->gravar($pedido->acao(), $pedido->campos, $achados, $contexto);
    }

    /**
     * @param array<string, mixed> $achados
     *
     * @throws ErroDaFerramenta
     */
    private function propor(DocumentosEPecasPedido $pedido, DocumentosEPecasGrupo $grupo, array $achados, DocumentosEPecasContexto $contexto, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $dados = $pedido->dados();
        // O texto de uma peça ou modelo pode passar do teto dos dados da proposta: é recusa da entrada, não erro do servidor.
        if (\strlen((string) json_encode($dados, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR)) > PropostasDoConector::TETO_DOS_DADOS - 1_000) {
            throw new ErroDaFerramenta(self::MOTIVO_TEXTO_GRANDE, self::TEXTO_GRANDE);
        }
        $resumo = $grupo->resumir($pedido->acao(), $pedido->campos, $achados, $contexto);

        return $this->propostas->propor($chamada, self::NOME, $pedido->chave, $dados, $resumo);
    }

    /**
     * A reconferência da confirmação, que a gravação repete: a ação continua por proposta, e a guarda, a pasta, o
     * segredo, o recurso de editar e tudo o que ela toca ainda valem agora. Não grava nada.
     *
     * @param array<string, mixed> $dados
     *
     * @return array{0: DocumentosEPecasPedido, 1: DocumentosEPecasGrupo, 2: array<string, mixed>, 3: DocumentosEPecasContexto}
     *
     * @throws ErroDaFerramenta
     */
    private function prepararConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): array
    {
        $pedido = DocumentosEPecasPedido::dosDadosDaProposta($acao, $dados);
        if (!$pedido->porProposta()) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $contexto = $this->contextoDa($pedido, $chamada);
        $grupo = $this->grupoDe($pedido->chave);

        return [$pedido, $grupo, $grupo->conferir($pedido->acao(), $pedido->campos, $contexto), $contexto];
    }

    /** @throws ErroDaFerramenta */
    private function contextoDa(DocumentosEPecasPedido $pedido, ChamadaDoConector $chamada): DocumentosEPecasContexto
    {
        $this->guarda->exigir($chamada, self::NOME, $pedido->chave);
        $escritorio = $chamada->contexto->escritorio;
        $pasta = $this->pastas->findOneBy(['id' => $pedido->pastaId, 'tenant' => $escritorio]);
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->guarda->exigir($chamada, self::NOME, $pedido->chave, $pasta);
        $abasOcultas = $this->liberar($chamada, $pedido->pastaId);
        // Toda ação grava na pasta: a tela só a abre a quem a edita.
        $this->guarda->exigirEdicaoDaPasta($chamada, $pasta);

        return new DocumentosEPecasContexto($pasta, $escritorio, $chamada->contexto->pessoa, $abasOcultas);
    }

    /**
     * O segredo de justiça e as abas ocultas, pela porta da tela (decisão 26).
     *
     * @return list<string> as abas ocultas para a pessoa
     *
     * @throws ErroDaFerramenta
     */
    private function liberar(ChamadaDoConector $chamada, int $pastaId): array
    {
        try {
            return $this->liberacao->liberar($chamada->contexto->pessoa, $chamada->contexto->escritorio, $pastaId)['abasOcultas'];
        } catch (PastaDaIaNaoEncontradaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (ContextoBloqueadoException) {
            throw ErroDaFerramenta::emSegredo();
        }
    }

    private function grupoDe(string $chave): DocumentosEPecasGrupo
    {
        return match (DocumentosEPecasAcoes::grupoDa($chave)) {
            DocumentosEPecasAcoes::GRUPO_DOCUMENTOS => $this->documentos,
            DocumentosEPecasAcoes::GRUPO_PECAS => $this->pecas,
            DocumentosEPecasAcoes::GRUPO_CHECKLIST => $this->checklist,
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }
}
