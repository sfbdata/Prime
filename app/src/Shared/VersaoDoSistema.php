<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * Único ponto de verdade da versão do BlueJus: a ÚLTIMA VERSÃO LANÇADA, sem o "v" (a tag é
 * `v` + este valor, num commit do master que passou no CI).
 *
 * Muda só no PR de release: no mesmo commit, a seção "[Não lançado]" do CHANGELOG.md vira
 * "[X.Y.Z] - AAAA-MM-DD". O tests/Arquitetura/VersaoDoSistemaTest trava os dois juntos, e o
 * workflow Release recusa criar a tag se esta constante não bater com a versão pedida.
 *
 * É daqui que a futura página de Novidades lê o número. Política e procedimento:
 * docs/versionamento.md.
 */
final class VersaoDoSistema
{
    public const ATUAL = '2.23.0';
}
