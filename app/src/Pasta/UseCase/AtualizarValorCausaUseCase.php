<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Shared\Service\ValorEmReais;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava o valor da causa de uma pasta a partir do que o humano digitou.
 *
 * A conversão em si mora em `ValorEmReais` — dinheiro não passa por float em
 * ponto nenhum do caminho. Entrada em pt-BR ("12.860,00", "R$ 12.860,00",
 * "12860"), saída no formato que o Doctrine grava em decimal(15,2) ("12860.00").
 *
 * Campo em branco limpa o cadastro (nulo), que é diferente de gravar R$ 0,00:
 * nulo fica de fora da média por CPF, zero entra nela.
 */
final class AtualizarValorCausaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, ?string $entrada): void
    {
        $pasta->setValorCausa(ValorEmReais::normalizar($entrada, 'valor dos honorários contratuais'));
        $this->em->flush();
    }
}
