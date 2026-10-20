<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Quem põe a cadeia do expansor para andar (spec §8.5): a confirmação da campanha ({@see self::acordar()}: `proxima_em
 * = agora` e despacha), a rede de segurança da varredura de 15 min ({@see self::socorrer()}: despacha se há campanha
 * ativa e a cadeia está mais de 5 min atrasada) e o `app:comercial:varredura` ({@see self::semear()}). Despachar a mais
 * é inofensivo: o passo que não reivindica a linha 1 morre.
 */
final class SementeDoExpansor
{
    public const ATRASO_DA_REDE = '-5 minutes';

    public function __construct(
        private readonly Connection $conexao,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function acordar(\DateTimeImmutable $agora): void
    {
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = :agora WHERE id = 1', ['agora' => $agora->format('Y-m-d H:i:s')]);
        $this->semear();
    }

    /** Devolve se despachou. */
    public function socorrer(\DateTimeImmutable $agora): bool
    {
        $atrasada = $this->conexao->fetchOne(
            'SELECT 1 FROM comercial_expansor WHERE id = 1 AND proxima_em < :limite',
            ['limite' => $agora->modify(self::ATRASO_DA_REDE)->format('Y-m-d H:i:s')],
        ) !== false;
        if (!$atrasada || !$this->campanhas->haAtivas()) {
            return false;
        }
        $this->semear();

        return true;
    }

    public function semear(): void
    {
        $this->bus->dispatch(new ExpandirCampanhas());
    }
}
