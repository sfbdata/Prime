<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;

/**
 * Provedor do espelho com resposta fixa: entrega sempre o mesmo espelho (em memória, nos testes) ou
 * se diz não configurado, como a fábrica de produção sem as variáveis `ESPELHO_R2_*`.
 *
 * Conta quantas vezes o espelho foi montado: a leitura que acha o arquivo no disco não pode montá-lo.
 */
final class ProvedorDoEspelhoFixo implements ProvedorDoEspelhoRemoto
{
    public int $montagens = 0;

    private function __construct(
        private readonly ?EspelhoRemoto $espelho,
    ) {
    }

    public static function com(EspelhoRemoto $espelho): self
    {
        return new self($espelho);
    }

    public static function naoConfigurado(): self
    {
        return new self(null);
    }

    public function estaConfigurado(): bool
    {
        return $this->espelho !== null;
    }

    public function criar(): EspelhoRemoto
    {
        if ($this->espelho === null) {
            throw new EspelhoRemotoNaoConfigurado('O espelho remoto não está configurado (dublê).');
        }
        ++$this->montagens;

        return $this->espelho;
    }
}
