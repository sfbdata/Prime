<?php

declare(strict_types=1);

namespace App\Processo\Integracao;

/**
 * Fonte "PJe" do cartão do processo (desenho: `BLUEJUS_API_BASE`, consulta ao PJe/MNI no servidor).
 * Hoje não há integração (P5a, decisão do dono: credencial MNI por tribunal): o cartão fica no
 * estado do desenho "Integração com o PJe não configurada. Preencha manualmente.". A integração
 * real entra trocando a implementação, sem mexer no cartão.
 */
interface IntegracaoComPjeInterface
{
    public function estaConfigurada(): bool;
}
