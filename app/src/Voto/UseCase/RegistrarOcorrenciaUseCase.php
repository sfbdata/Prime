<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\TransacaoDoVoto;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Registrar ocorrência de uma unidade (spec voto-avaliacoes-do-conselho-1611, seção 2): a qualquer momento até a
 * assembleia acabar, de 1 a 80 caracteres. Só acrescenta (cada registro é uma linha nova). Unidade NÃO apta abre uma
 * avaliação `pendente` para o conselho; unidade apta só registra, sem avaliação. Com avaliação pendente aberta, a nova
 * ocorrência é recusada.
 *
 * Quem pode: {@see AcessoAAssembleia::podeRegistrarOcorrencia()}, conferido aqui também (o conselho tem o nível
 * Visualizar e nunca registra). Assembleia e unidade de outro escritório dão 404 (guarda de posse). Grava a trilha
 * "Ocorrência registrada" na mesma transação.
 */
final class RegistrarOcorrenciaUseCase
{
    private const JA_PENDENTE = 'Já há avaliação pendente para esta unidade.';

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly OcorrenciaDaUnidadeRepository $ocorrencias,
        private readonly AcessoAAssembleia $acesso,
        private readonly RegistrarAuditoriaDasAvaliacoesUseCase $auditoria,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    /**
     * @throws AssembleiaNaoEncontradaException       assembleia ou unidade fora do escritório
     * @throws SemPapelNaAvaliacaoException           a pessoa não registra ocorrência nesta assembleia
     * @throws OperacaoInvalidaNaAssembleiaException  texto vazio, assembleia acabada ou avaliação já pendente
     */
    public function executar(Tenant $tenant, int $assembleiaId, int $unidadeId, string $texto, User $ator, string $porNome, ?\DateTimeImmutable $agora = null): OcorrenciaDaUnidade
    {
        $agora ??= new \DateTimeImmutable();
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia)
            ?? throw new AssembleiaNaoEncontradaException();
        if (!$this->acesso->podeRegistrarOcorrencia($ator, $assembleia)) {
            throw new SemPapelNaAvaliacaoException();
        }
        $texto = OcorrenciaDaUnidade::textoValido($texto);
        if ($assembleia->acabou($agora)) {
            throw new OperacaoInvalidaNaAssembleiaException('A votação terminou: não é mais possível registrar ocorrência.');
        }

        try {
            return $this->gravarOcorrencia($tenant, $assembleia, $unidade, $texto, $ator, $porNome, $agora);
        } catch (UniqueConstraintViolationException) {
            // Corrida: dois registros na mesma unidade; o índice único parcial deixou só um. Mesma resposta da checagem.
            throw new OperacaoInvalidaNaAssembleiaException(self::JA_PENDENTE);
        }
    }

    private function gravarOcorrencia(Tenant $tenant, Assembleia $assembleia, UnidadeDaAssembleia $unidade, string $texto, User $ator, string $porNome, \DateTimeImmutable $agora): OcorrenciaDaUnidade
    {
        return $this->transacao->executar(function () use ($tenant, $assembleia, $unidade, $texto, $ator, $porNome, $agora): OcorrenciaDaUnidade {
            $abreAvaliacao = !$unidade->isApta();
            if ($abreAvaliacao && null !== $this->ocorrencias->pendenteDaUnidade($unidade)) {
                throw new OperacaoInvalidaNaAssembleiaException(self::JA_PENDENTE);
            }
            $ocorrencia = new OcorrenciaDaUnidade($assembleia, $unidade, $texto, OrigemDaOcorrencia::Manual, $porNome, $abreAvaliacao, $agora);
            $this->ocorrencias->salvar($ocorrencia);
            $this->transacao->gravar();
            $this->auditoria->ocorrenciaRegistrada($tenant, $ator, $assembleia, $ocorrencia);

            return $ocorrencia;
        });
    }
}
