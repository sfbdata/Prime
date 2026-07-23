<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

final class EnviarObservacaoFinanceiraUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {}

    public function executar(Pasta $pasta, User $autor, string $conteudo, Tenant $tenant): PastaObservacaoFinanceira
    {
        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $obs = new PastaObservacaoFinanceira();
        $obs->setPasta($pasta);
        $obs->setAutor($autor);
        $obs->setTenant($tenant);
        $obs->setConteudo($conteudo);

        $this->em->persist($obs);
        $this->em->flush();

        return $obs;
    }
}
