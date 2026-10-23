<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Tenant\Tenant;
use App\Entity\Auth\User;
use App\EventListener\TermoAceiteListener;
use App\Termo\TermoVigente;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class JusPrimeWebTestCase extends WebTestCase
{
    protected function logarComTenant(
        KernelBrowser $client,
        User $user,
        Tenant $tenant,
    ): void {
        $client->loginUser($user);
        $session = $client->getSession();
        $session->set('current_tenant_id', $tenant->getId());
        $session->set(TermoAceiteListener::SESSION_KEY, TermoVigente::VERSAO);
        $session->save();
    }

    /**
     * Sai como o link "Sair" do sistema: o logout exige o token `logout` (enable_csrf), que é stateless e vale
     * pela origem da requisição. O navegador manda o Sec-Fetch-Site; o KernelBrowser não, então vai aqui.
     */
    protected function sair(KernelBrowser $client): void
    {
        $client->request('GET', '/logout?_csrf_token=csrf-token', [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /**
     * Marca os Termos de Uso como aceitos na sessão, satisfazendo o gate
     * (TermoAceiteListener) sem precisar persistir um AceiteTermo. Use em testes
     * que autenticam via loginUser direto, sem logarComTenant.
     */
    protected function marcarTermosAceitos(KernelBrowser $client): void
    {
        $session = $client->getSession();
        $session->set(TermoAceiteListener::SESSION_KEY, TermoVigente::VERSAO);
        $session->save();
    }
}
