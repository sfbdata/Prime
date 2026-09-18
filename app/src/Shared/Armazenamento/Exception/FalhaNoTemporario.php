<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento\Exception;

/**
 * A falha foi do lado do TEMPORÁRIO, não do arquivo persistido (D26, E2.6A).
 *
 * O `/tmp` encheu, o diretório privado não é privado, o `tempnam()` caiu fora: nada disso diz que o
 * arquivo do cliente está em risco — ele nem foi tocado. É a diferença que a D26 exige e que um
 * único tipo não expressava: "não consegui LER o persistido" tem de subir (pane), enquanto "não
 * consegui preparar o temporário" pode virar log e seguir sem comprimir, com o original intacto.
 *
 * Continua sendo uma `FalhaDeArmazenamento`: quem não precisa da distinção (a ponte de upload, por
 * exemplo, onde as duas são fatais) captura a base e não muda de comportamento.
 */
final class FalhaNoTemporario extends FalhaDeArmazenamento
{
}
