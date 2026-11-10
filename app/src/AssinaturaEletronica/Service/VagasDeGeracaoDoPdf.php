<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use App\AssinaturaEletronica\Exception\PdfDoDocumentoSendoGerado;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\ExceptionInterface as FalhaDaTrava;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * No máximo {@see VAGAS} PDFs com selo gerados ao mesmo tempo na web (cerca de 1,2 s por página; o FPM tem 5 filhos):
 * uma trava por vaga na loja do banco (`lock.assinatura_pdf.factory`, vale entre contêineres), tentada sem esperar. Sem
 * vaga livre, {@see MuitosPdfsSendoGerados}. Quem ocupa libera ao terminar; a trava também expira sozinha ({@see TTL},
 * acima dos 300 s do `set_time_limit` das ações do PDF) e sai no fim do processo. O worker não passa por aqui.
 *
 * Concluído sem PDF guardado: uma trava por documento ({@see travarDocumento()}), na mesma loja e sem esperar, para
 * dois pedidos da web não gerarem o mesmo PDF; ocupada, {@see PdfDoDocumentoSendoGerado}. Quem gera renova as travas a
 * cada pedaço ({@see renovar()}): o TTL não vence durante uma geração longa, com o gs em subprocesso.
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

    /** @throws PdfDoDocumentoSendoGerado */
    public function travarDocumento(int $documento): LockInterface
    {
        $trava = $this->travas->createLock('assinatura_pdf_documento_' . $documento, self::TTL);
        if (!$trava->acquire(false)) {
            throw new PdfDoDocumentoSendoGerado();
        }

        return $trava;
    }

    /**
     * Mais um {@see TTL} para cada trava, a cada pedaço gerado. A trava que não renova (venceu ou a loja falhou) não
     * para a geração: o PDF já está saindo, e a vaga é só um freio.
     */
    public static function renovar(?LockInterface ...$travas): void
    {
        foreach ($travas as $trava) {
            try {
                $trava?->refresh();
            } catch (FalhaDaTrava) {
                continue;
            }
        }
    }
}
