<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * A marca "o selo deste documento já está nas tentativas do worker" (spec assinatura-documentos-etapa-8, E8-4): sem
 * ela, cada clique em "Baixar PDF com selo" durante a falha geraria o PDF de novo, pediria o selo e abriria outra
 * cadeia de 4 tentativas.
 *
 * Uma trava por documento na mesma loja das vagas da geração (`lock.assinatura_pdf.factory`, no banco: vale para a web
 * e para o worker), com {@see TTL_EM_SEGUNDOS} fixo e sem soltar no fim do processo. A marca expira sozinha, depois do
 * tempo das 4 tentativas; só a solta quem acabou de marcar e não conseguiu passar o pedido ao worker.
 *
 * - O worker marca quando o selo falha e a mensagem volta à fila.
 * - A web, antes de gerar, pergunta {@see estaPendente()}: pendente, responde que o PDF está sendo gerado sem gerar. Na
 *   falha do selo, só passa o pedido ao worker se {@see marcar()} marcou agora, e solta a marca nova se a fila falhar.
 * - O vínculo à pasta, com a cadeia pendente, não pede o PDF ao worker: o vínculo segue pendente até o PDF guardado.
 */
final readonly class CadeiaDoSeloPendente
{
    /** As esperas de 1, 5 e 15 min entre as tentativas, mais o tempo das gerações. */
    public const TTL_EM_SEGUNDOS = 1800.0;

    private const RECURSO = 'assinatura_pdf_selo_pendente_';

    public function __construct(
        #[Autowire(service: 'lock.assinatura_pdf.factory')]
        private LockFactory $travas,
    ) {
    }

    /**
     * A marca posta agora, ou nulo quando a cadeia deste documento já estava marcada. Quem marcou e não conseguiu passar
     * o pedido ao worker solta a marca nova ({@see LockInterface::release()}); fora isso, ninguém solta.
     */
    public function marcar(int $documentoId): ?LockInterface
    {
        $trava = $this->trava($documentoId);

        return $trava->acquire(false) ? $trava : null;
    }

    /** Sonda a marca sem deixar nenhuma: se a trava estava livre, solta na hora. */
    public function estaPendente(int $documentoId): bool
    {
        $trava = $this->trava($documentoId);
        if (!$trava->acquire(false)) {
            return true;
        }

        $trava->release();

        return false;
    }

    private function trava(int $documentoId): LockInterface
    {
        return $this->travas->createLock(self::RECURSO . $documentoId, self::TTL_EM_SEGUNDOS, false);
    }
}
