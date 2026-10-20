<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Quem é a Controladoria do escritório: as pessoas que recebem a cópia (CC) obrigatória de todo
 * e-mail ao cliente (spec push-avisar-cliente, "Decisões"; desenho `mailCc`, dc L.5227).
 *
 * Isolada atrás desta interface porque a marca mora em `user_tenant.controladoria`, do #75, que ainda
 * não está no master. Até lá vale {@see ControladoriaDoEscritorioProvisoria} (ninguém: o envio sai sem
 * cópia e o primário é avisado). Quando o #75 entrar, a troca é só de implementação.
 */
interface ControladoriaDoEscritorioInterface
{
    /**
     * As pessoas ATIVAS do escritório marcadas como Controladoria, com e-mail.
     *
     * @return list<User>
     */
    public function pessoas(Tenant $tenant): array;
}
