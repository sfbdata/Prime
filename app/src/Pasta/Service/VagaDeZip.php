<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Uma vaga de .zip ocupada (SEC-004b): o descritor do arquivo de trava, com `flock` exclusivo.
 *
 * `liberar()` é idempotente — o controller pode devolver cedo e o `kernel.terminate` chamar de
 * novo. O `__destruct` é a rede de segurança: objeto que some devolve a vaga.
 */
final class VagaDeZip
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle já travado com LOCK_EX */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function liberar(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function ocupada(): bool
    {
        return $this->handle !== null;
    }

    public function __destruct()
    {
        $this->liberar();
    }
}
