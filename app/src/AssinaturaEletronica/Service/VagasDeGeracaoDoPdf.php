<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * No máximo {@see VAGAS} PDFs com selo gerados ao mesmo tempo na web (cerca de 1,2 s por página; o FPM tem 5 filhos):
 * uma trava por vaga na loja do banco (`lock.assinatura_pdf.factory`, vale entre contêineres), tentada sem esperar. Sem
 * vaga livre, {@see MuitosPdfsSendoGerados}. Quem ocupa libera ao terminar; a trava também expira sozinha ({@see TTL},
 * acima dos 300 s do `set_time_limit` das ações do PDF) e sai no fim do processo. O worker não passa por aqui.
 */
final readonly class VagasDeGeracaoDoPdf
{
    public const VAGAS = 2;
    private const TTL = 330.0;

    public function __construct(
        #[Autowire(service: 'lock.assinatura_pdf.factory')]
        private LockFactory $travas,
    ) {
    }

    /** @throws MuitosPdfsSendoGerados */
    public function ocupar(): LockInterface
    {
        for ($vaga = 0; $vaga < self::VAGAS; ++$vaga) {
            $trava = $this->travas->createLock('assinatura_pdf_vaga_' . $vaga, self::TTL);
            if ($trava->acquire(false)) {
                return $trava;
            }
        }

        throw new MuitosPdfsSendoGerados();
    }
}
