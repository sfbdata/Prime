<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Service\CadernoDeEmailsDoCliente;

/**
 * Um e-mail do cliente como o cartão Avisar cliente o mostra (desenho `lista`, dc L.5261-5265).
 * `id` 0 = o item virtual do cadastro. `recebendo` = recebe avisos e não está descadastrado.
 */
final readonly class EmailDoClienteOutput
{
    public function __construct(
        public int $id,
        public string $email,
        public string $rotulo,
        public bool $principal,
        public bool $recebe,
        public bool $descadastrado,
    ) {
    }

    public static function de(ClienteEmail $email, bool $descadastrado): self
    {
        return new self(
            $email->getId() ?? CadernoDeEmailsDoCliente::ID_DO_CADASTRO,
            $email->getEmail(),
            $email->getRotulo()->rotulo(),
            $email->ehPrincipal(),
            $email->recebeAvisos(),
            $descadastrado,
        );
    }

    public function recebendo(): bool
    {
        return $this->recebe && !$this->descadastrado;
    }

    /** "Pessoal · principal · pausado" (dc L.5261 `sub`). */
    public function sub(): string
    {
        return $this->rotulo . ($this->principal ? ' · principal' : '') . ($this->recebe ? '' : ' · pausado');
    }

    public function dicaDoSwitch(): string
    {
        if ($this->descadastrado) {
            return 'O cliente pediu para não receber mais estes avisos. Só ele pode voltar a receber.';
        }

        return $this->recebe ? 'Recebendo avisos (clique para pausar)' : 'Pausado (clique para voltar a receber)';
    }
}
