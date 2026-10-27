<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\HtmlDoTermoDeVoluntariado;
use App\TrabalhoExterno\Service\LocalizadorDeTermo;
use App\TrabalhoExterno\Service\PoliticaDoTermo;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Aceitar termo" (`aceitarTermo`, desenho 06 de 25/10, l.1135): só o dono do currículo, só com o termo `enviado` e só
 * com o "Li e aceito" marcado. O SERVIDOR grava quando (o relógio dele), quem, o IP, o user-agent e o sha256 do HTML
 * como renderizado neste instante (o mesmo que a prévia, o Word e a impressão mostram). Aceito não aceita de novo.
 * Avisa a Central (sino). Quem não é o dono nem enxerga o termo (404).
 */
final class AceitarTermoUseCase
{
    public const TEXTO_SEM_MARCA = 'Marque "Li e aceito" para confirmar.';

    public function __construct(
        private readonly LocalizadorDeTermo $localizador,
        private readonly PoliticaDoTermo $politica,
        private readonly HtmlDoTermoDeVoluntariado $html,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly TermoDeVoluntariadoRepository $termos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Contexto $ctx, string $codigoDoTermo, bool $liEAceito, ?string $ip, ?string $userAgent): TermoDeVoluntariado
    {
        $termo = $this->localizador->localizar($ctx, $codigoDoTermo);

        // A linha do termo fica travada (`SELECT … FOR UPDATE`) e relida antes de olhar o estado: dois aceites
        // juntos se enfileiram, só o primeiro grava e avisa, o segundo cai em 409 sem reescrever data, IP, UA nem hash.
        return $this->em->wrapInTransaction(function () use ($ctx, $termo, $liEAceito, $ip, $userAgent): TermoDeVoluntariado {
            $this->em->refresh($termo, LockMode::PESSIMISTIC_WRITE);
            $this->conferir($ctx, $termo, $liEAceito);
            $agora = new \DateTimeImmutable();
            $hash = hash('sha256', $this->html->montar($termo->getCampos(), HtmlDoTermoDeVoluntariado::dataHora($agora), $termo->getCodigo()));
            $termo->aceitar($ctx->usuario, $agora, $ip, $userAgent, $hash);
            $this->avisos->termoAceito($ctx, $termo);
            $this->em->flush();

            return $termo;
        });
    }

    private function conferir(Contexto $ctx, TermoDeVoluntariado $termo, bool $liEAceito): void
    {
        if ($termo->foiAceito()) {
            throw new TransicaoInvalida('Este termo já foi aceito.');
        }
        if (!$this->politica->podeAceitar($ctx, $termo)) {
            throw new AcaoNaoPermitida('Só o voluntário dono do currículo aceita o termo.');
        }
        $maisRecente = $this->termos->maisRecenteDo($termo->getCurriculo());
        if ($maisRecente !== null && $maisRecente->getCodigo() !== $termo->getCodigo()) {
            throw new TransicaoInvalida('Existe um termo mais novo para este currículo. Aceite o mais recente.');
        }
        if (!$liEAceito) {
            throw new DadosInvalidos(self::TEXTO_SEM_MARCA);
        }
    }
}
