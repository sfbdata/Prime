<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O link curto `/i/{codigo}` de cada pessoa (spec `indicar-bluejus.md`, comportamento 7). O código é sorteado (8
 * símbolos de um alfabeto sem 0/O e 1/I, 40 bits), nunca derivado do id; nasce no primeiro pedido e não muda mais.
 */
final class CodigoDeIndicacao
{
    public const ROTA = 'indicacao_link_curto';
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const TAMANHO = 8;
    public const PADRAO = '[A-HJ-NP-Z2-9]{8}';

    private const TENTATIVAS = 5;

    public function __construct(
        private readonly IndicacaoCodigoRepository $codigos,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function linkCurtoDe(User $user): string
    {
        return $this->urls->generate(self::ROTA, ['codigo' => $this->codigoDe($user)], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function codigoDe(User $user): string
    {
        $userId = (int) $user->getId();
        $codigo = $this->codigos->codigoDoUsuario($userId);
        for ($i = 0; $codigo === null && $i < self::TENTATIVAS; ++$i) {
            $codigo = $this->codigos->gravarSeNaoTiver($userId, self::sortear());
        }

        return $codigo ?? throw new \RuntimeException('Não foi possível gerar o código de indicação.');
    }

    public static function sortear(): string
    {
        $codigo = '';
        for ($i = 0; $i < self::TAMANHO; ++$i) {
            $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $codigo;
    }
}
