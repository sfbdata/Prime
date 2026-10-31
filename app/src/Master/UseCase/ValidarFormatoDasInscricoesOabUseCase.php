<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Service\InscricaoOab;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Validar agora" da Revisão de OAB (desenho 05 - Perfis 1.2 de 31/10). Com a consulta ao CNA desligada, confere só o
 * FORMATO de cada inscrição que não está Confirmada, com a regra do desenho: a inscrição normalizada (sem zeros à
 * esquerda) tem de 3 a 7 dígitos e UF de duas letras. Dígitos e UF maiúscula o banco já garante (CHECK de
 * inscricao_oab); o que sobra é o tamanho. Formato inválido vai para Divergente; formato válido fica como está. Nunca confirma: confirmar exige o nome no CNA (decisão do dono).
 * Grava a hora da rodada na configuração da revisão. Contagem como no desenho: conferidas são as de formato válido,
 * inválidas as que foram para Divergente. Auditado pelo AuditLogSubscriber no escopo de plataforma.
 */
final class ValidarFormatoDasInscricoesOabUseCase
{
    public const FORMATO = '/^\d{3,7}\/[A-Z]{2}$/';
    private const TETO = 5000;

    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /** @return array{conferidas: int, invalidas: int} */
    public function executar(): array
    {
        return $this->escopoDePlataforma->executar(fn (): array => $this->em->wrapInTransaction(function (): array {
            $conferidas = 0;
            $invalidas = 0;
            foreach ($this->inscricoes->paraRevisao([StatusOab::NaoVerificada, StatusOab::Divergente], '', 1, self::TETO) as $i) {
                if (preg_match(self::FORMATO, InscricaoOab::normalizar($i->getNumero(), $i->getUf())) === 1) {
                    $conferidas++;
                    continue;
                }
                $invalidas++;
                if ($i->getStatus() !== StatusOab::Divergente) {
                    $i->registrarVerificacao(StatusOab::Divergente, $i->getNomeOficial(), $this->relogio->now());
                    if ($i->ehPrincipal()) {
                        $i->espelharNoUsuario();
                    }
                    $this->inscricoes->salvar($i);
                }
            }

            $linha = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
            $linha->definirRevisaoOab(['ultimaRodada' => $this->relogio->now()->format(\DateTimeInterface::ATOM)]);
            $this->configuracoes->salvar($linha);

            return ['conferidas' => $conferidas, 'invalidas' => $invalidas];
        }));
    }
}
