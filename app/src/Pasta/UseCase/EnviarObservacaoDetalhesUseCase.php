<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

final class EnviarObservacaoDetalhesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {}

    public function executar(Pasta $pasta, User $autor, string $conteudo, Tenant $tenant): PastaObservacaoDetalhes
    {
        // O conteúdo vem do editor rico (HTML) — limpo ANTES de persistir, para o banco nunca
        // guardar marcação perigosa. Texto puro atravessa intacto (ver SanitizadorTextoRico).
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` em vez de `=== ''`: o editor entrega `<p><br></p>` quando nada foi digitado.
        // O limite conta o texto visível, não a marcação — senão formatar reduziria o que cabe.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setAutor($autor);
        $obs->setTenant($tenant);
        $obs->setConteudo($conteudo);

        $this->em->persist($obs);
        $this->em->flush();

        return $obs;
    }
}
