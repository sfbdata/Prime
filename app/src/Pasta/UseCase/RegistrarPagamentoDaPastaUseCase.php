<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Shared\Service\ValorEmReais;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lança um pagamento a receber na pasta: honorário, parcela de honorário ou
 * reembolso de custas. Nasce sempre PENDENTE — quitar é outro gesto, e o
 * lançamento com a quitação junta esconderia a data em que o dinheiro entrou.
 */
final class RegistrarPagamentoDaPastaUseCase
{
    private const MAX_DESCRICAO = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param string $vencimento data no formato do campo `date` do navegador (AAAA-MM-DD)
     *
     * @throws \InvalidArgumentException quando descrição, valor ou data não servem
     */
    public function executar(
        Pasta $pasta,
        User $autor,
        Tenant $tenant,
        string $descricao,
        string $valor,
        string $vencimento,
    ): PastaPagamento {
        $descricao = trim($descricao);

        if ($descricao === '') {
            throw new \InvalidArgumentException('Descreva o que é este pagamento.');
        }

        if (mb_strlen($descricao) > self::MAX_DESCRICAO) {
            throw new \InvalidArgumentException(
                sprintf('A descrição pode ter no máximo %d caracteres.', self::MAX_DESCRICAO)
            );
        }

        $decimal = ValorEmReais::normalizar($valor, 'valor do pagamento');

        // Aqui o branco NÃO vira nulo como no valor da causa: um pagamento sem
        // valor não é um "não sei", é uma linha que não deveria existir.
        if ($decimal === null || ValorEmReais::paraCentavos($decimal) <= 0) {
            throw new \InvalidArgumentException('Informe um valor maior que zero.');
        }

        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setAutor($autor);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor($decimal);
        $pagamento->setVencimento($this->lerVencimento($vencimento));

        $this->em->persist($pagamento);
        $this->em->flush();

        return $pagamento;
    }

    /**
     * `createFromFormat` com `!` zera a hora — sem isso a data nasceria com a
     * hora do lançamento e a comparação de vencimento erraria o dia da borda.
     *
     * A volta ao texto é o que recusa data inexistente: `31/02` NÃO devolve
     * `false`, ele rola para 03/03 em silêncio — e um vencimento inventado é
     * pior que um erro na tela.
     */
    private function lerVencimento(string $entrada): \DateTimeImmutable
    {
        $entrada = trim($entrada);
        $data    = \DateTimeImmutable::createFromFormat('!Y-m-d', $entrada);

        if ($data === false || $data->format('Y-m-d') !== $entrada) {
            throw new \InvalidArgumentException('Informe uma data de vencimento válida.');
        }

        return $data;
    }
}
