<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Entity\Auth\User;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marcar e cancelar o fim de um convênio (spec docs/specs/convenios.md, §6.2), para o desligar de um convênio e o de
 * uma instituição inteira. Roda DENTRO da transação de quem chama, depois da trava: grava o convênio (Trilha da
 * plataforma), os vínculos (Trilha de cada escritório) e enfileira os avisos. Falha ao enfileirar propaga e desfaz tudo.
 *
 * Regras: o fim não é antes de hoje; com benefício real (desconto ou espaço extra), o aviso tem 30 dias de antecedência;
 * só gratuidade pode desligar na hora (fim = hoje). Os vínculos de quem desligou na hora fecham pela sincronização,
 * que o chamador roda depois do commit ({@see \App\Master\UseCase\SincronizarVinculosDeConvenioUseCase::dosEscritorios()}).
 */
final class FimDoConvenio
{
    public const DIAS_DE_AVISO = 30;

    public function __construct(
        private readonly ConvenioRepository $convenios,
        private readonly ConvenioVinculoRepository $vinculos,
        private readonly AvisoDeConvenio $aviso,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** A data do fim (AAAA-MM-DD) pedida pelo Master. */
    public static function dia(?string $texto): \DateTimeImmutable
    {
        $dia = $texto === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if ($dia === false || $dia->format('Y-m-d') !== $texto) {
            throw new ConvenioRecusado('Informe a data do fim (AAAA-MM-DD).');
        }

        return $dia;
    }

    /** O primeiro dia permitido para o fim de um convênio com benefício real. */
    public static function prazoMinimo(\DateTimeImmutable $hoje): \DateTimeImmutable
    {
        return $hoje->setTime(0, 0)->modify('+' . self::DIAS_DE_AVISO . ' days');
    }

    /** Confere a data antes de mexer em qualquer coisa. */
    public function exigirDataPermitida(Convenio $convenio, \DateTimeImmutable $dia, \DateTimeImmutable $hoje): void
    {
        if ($dia->format('Y-m-d') < $hoje->format('Y-m-d')) {
            throw new ConvenioRecusado('A data do fim não pode ser antes de hoje.');
        }
        if ($convenio->getBeneficio()->ehReal() && $dia->format('Y-m-d') < self::prazoMinimo($hoje)->format('Y-m-d')) {
            throw new ConvenioRecusado(sprintf('O convênio %s tem benefício real: o fim é avisado com %d dias de antecedência.', $convenio->getNome(), self::DIAS_DE_AVISO));
        }
    }

    /**
     * Marca o fim e avisa os primários dos escritórios vinculados. Fim hoje: desliga já.
     *
     * @return array{avisados: int, tenants: list<int>, desligouAgora: bool}
     */
    public function marcar(Convenio $convenio, \DateTimeImmutable $dia, ?string $motivo, ?User $ator, \DateTimeImmutable $agora): array
    {
        $this->exigirDataPermitida($convenio, $dia, $agora);
        $convenio->marcarFim($dia, $motivo, $ator, $agora);
        $desligouAgora = $dia->format('Y-m-d') === $agora->format('Y-m-d');
        if ($desligouAgora) {
            $convenio->desligar($ator, $agora);
        }
        $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($convenio, flush: true));

        // Desligou na hora: avisa todos os vinculados. Fim marcado: só os escritórios cujo dia previsto mudou (repetir o
        // pedido com a mesma data não reenvia o aviso).
        $vinculos = $this->vinculos->abertosDosConvenios([(int) $convenio->getId()]);
        $tenants = self::tenants($vinculos);
        $avisar = $desligouAgora ? $tenants : $this->gravarPorEscritorio($vinculos, static fn (ConvenioVinculo $v): bool => $v->avisarFim($dia, $agora));
        $avisados = $this->aviso->avisar($convenio, $dia, $this->aviso->destinatarios($avisar));

        return ['avisados' => $avisados, 'tenants' => $tenants, 'desligouAgora' => $desligouAgora];
    }

    /**
     * Religa: cancela o fim marcado (avisa quem tinha sido avisado) ou liga de novo o desligado (sem e-mail).
     *
     * @return int|null quantos avisos "o convênio continua" entraram na fila; null: já estava ligado, sem fim marcado
     */
    public function cancelarOuLigar(Convenio $convenio, ?User $ator, \DateTimeImmutable $agora): ?int
    {
        if (!$convenio->isAtivo()) {
            $convenio->ligar($ator, $agora);
            $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($convenio, flush: true));

            return 0;
        }
        if (!$convenio->cancelarFim($ator, $agora)) {
            return null;
        }
        $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($convenio, flush: true));
        $avisados = $this->gravarPorEscritorio($this->vinculos->abertosDosConvenios([(int) $convenio->getId()]), static fn (ConvenioVinculo $v): bool => $v->cancelarFimPrevisto());

        return $this->aviso->avisar($convenio, null, $this->aviso->destinatarios($avisados));
    }

    /**
     * Aplica a mudança em cada vínculo e grava escritório por escritório, para a linha da Trilha ficar no escritório
     * do vínculo (spec §3.4, "Trilha no escritório certo").
     *
     * @param list<ConvenioVinculo>           $vinculos
     * @param callable(ConvenioVinculo): bool $mudar    devolve se o vínculo mudou
     *
     * @return list<int> os escritórios que mudaram
     */
    private function gravarPorEscritorio(array $vinculos, callable $mudar): array
    {
        $porTenant = [];
        foreach ($vinculos as $vinculo) {
            $porTenant[(int) $vinculo->getTenant()->getId()][] = $vinculo;
        }
        $mudaram = [];
        foreach ($porTenant as $tenantId => $doEscritorio) {
            $mudou = array_filter(array_map($mudar, $doEscritorio));
            if ($mudou !== []) {
                $this->escopoDePlataforma->executarComoConta($tenantId, fn () => $this->em->flush());
                $mudaram[] = $tenantId;
            }
        }

        return $mudaram;
    }

    /**
     * @param list<ConvenioVinculo> $vinculos
     *
     * @return list<int>
     */
    private static function tenants(array $vinculos): array
    {
        return array_values(array_unique(array_map(static fn (ConvenioVinculo $v): int => (int) $v->getTenant()->getId(), $vinculos)));
    }
}
