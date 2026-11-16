<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDaDuplicacao;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TokenDaUnidade;

/**
 * Duplica a assembleia (em qualquer estado) como um NOVO rascunho do mesmo escritório, com " (cópia)" no título.
 *
 * Copia só o que é cadastro: título, peso, entidade (nome e CNPJ), as pautas (título, descrição, tipo, regras, quóruns e opções com a
 * abstenção) e a lista de unidades (identificação, bloco, situação, fração, responsável e telefone). O e-mail fica de fora, como
 * no desenho ("sem os e-mails"): é o canal do código e do link de acesso, e a cópia é outra votação.
 *
 * Não copia nada de acesso, voto ou andamento: código público, estado, publicação, prazos, convocação, abertura e
 * encerramento das pautas, contadores de votos e nulos, participações, liberações e, nas unidades, o link (cada cópia
 * ganha semente e token novos, pois o do original continua valendo só para ele), o envio do link e os códigos de
 * confirmação. O sistema não guarda datas na assembleia, então a regra de datas do desenho não se aplica.
 */
final class DuplicarAssembleiaUseCase
{
    public const SUFIXO_DA_COPIA = ' (cópia)';
    private const TITULO_MAXIMO = 160;

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly TokenDaUnidade $tokens,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly FotosDasCandidaturas $fotos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $criadaPorNome): ResultadoDaDuplicacao
    {
        return $this->serializacao->executar(
            $tenant,
            $assembleiaId,
            fn (): ResultadoDaDuplicacao => $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $criadaPorNome),
        );
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, string $criadaPorNome): ResultadoDaDuplicacao
    {
        $origem = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();

        $titulo = mb_substr($origem->getTitulo(), 0, self::TITULO_MAXIMO - mb_strlen(self::SUFIXO_DA_COPIA)) . self::SUFIXO_DA_COPIA;
        $copia = new Assembleia($tenant, $titulo, $origem->getPeso(), $criadaPorNome);
        $copia->identificarEntidade($origem->getEntidadeNome(), $origem->getCnpj());
        $fotos = [];
        $semEmails = false;
        try {
            foreach ($origem->getPautas() as $pauta) {
                array_push($fotos, ...$this->copiarPauta($copia, $pauta));
            }
            foreach ($origem->getUnidades() as $unidade) {
                $this->copiarUnidade($copia, $unidade);
                $semEmails = $semEmails || null !== $unidade->getEmail();
            }
        } catch (\Throwable $falha) {
            $this->fotos->descartar($fotos, 'cópia de foto (duplicação interrompida)');

            throw $falha;
        }
        $this->fotos->persistirComCopias($fotos, fn () => $this->assembleias->salvar($copia, flush: true));

        return new ResultadoDaDuplicacao((int) $copia->getId(), $copia->getUnidades()->count(), $semEmails);
    }

    /** @return list<\App\Shared\Armazenamento\ChaveDeArquivo> as fotos copiadas (arquivos novos) */
    private function copiarPauta(Assembleia $copia, PautaDaAssembleia $origem): array
    {
        $pauta = new PautaDaAssembleia($copia, $origem->getOrdem(), $origem->getTitulo(), $origem->getDescricao(), $origem->getTipo());
        $segunda = $origem->getQuorumSegunda();
        $pauta->configurar(
            $origem->getCriterio(),
            $origem->getBase(),
            $origem->getDigitos(),
            $origem->getVagas(),
            (int) $origem->getQuorumPrimeira(),
            null === $segunda ? null : (int) $segunda,
        );
        foreach ($origem->getOpcoes() as $opcao) {
            (new OpcaoDaPauta($pauta, $opcao->getNumero(), $opcao->getRotulo(), $opcao->isAbstencao()))
                ->detalhar($opcao->getDescricao(), $opcao->getPropostas(), $opcao->getComposicao(), $opcao->getUnidadeCandidato());
        }

        return $this->fotos->copiar($origem, $pauta);
    }

    private function copiarUnidade(Assembleia $copia, UnidadeDaAssembleia $origem): void
    {
        $semente = $this->tokens->novaSemente();
        new UnidadeDaAssembleia(
            $copia,
            $origem->getIdentificacao(),
            $semente,
            $this->tokens->hash($this->tokens->token($semente)),
            $origem->getBloco(),
            $origem->getSituacao(),
            $origem->getFracao(),
            $origem->getResponsavel(),
            null,
            $origem->getTelefone(),
        );
    }
}
