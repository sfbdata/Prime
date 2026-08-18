<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\Cliente;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Define qual cliente vinculado representa a pasta nos indicadores.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: escolher de quem é a "Média por CPF" da
 * aba Financeiro, quando a pasta tem mais de um cliente. Por quê: até aqui o sistema escolhia
 * sozinho, pelo cliente de cadastro mais antigo — e vincular depois um cliente mais antigo
 * TROCAVA o número na tela, sem ninguém ter pedido.
 *
 * Erros: o cliente precisa já estar vinculado à pasta; caso contrário a entidade lança
 * \DomainException. Marcar um novo principal substitui o anterior — a marcação é uma coluna, então
 * "exatamente um" é garantido pelo banco, não por invariante de código.
 */
final class DefinirClientePrincipalUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, Cliente $cliente): void
    {
        $pasta->definirClientePrincipal($cliente);
        $this->em->flush();
    }
}
