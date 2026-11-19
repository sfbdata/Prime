<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Os botões do cartão em "Recebidas" (spec acesso-restrito-e-compartilhar §4.5, fatia 8; desenho L10): "Aceitar e
 * colaborar" e "Recusar" no convite que aguarda; "Deixar de colaborar" no aceito. Só o destino responde: o convite de
 * outra pessoa, já respondido ou vencido dá a mesma recusa (não confirma que existe).
 *
 * O aceite não dá vínculo com o escritório da pasta: as rotas da pasta recebida são da fatia 9.
 */
final class ResponderPastaRecebidaUseCase
{
    public const ACEITAR = 'aceitar';
    public const RECUSAR = 'recusar';
    public const DEIXAR = 'deixar';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $compartilhamentoId, string $acao, User $pessoa): PastaCompartilhamento
    {
        $convite = $this->compartilhamentos->recebidaPor($compartilhamentoId, $pessoa)
            ?? throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CONVITE_INDISPONIVEL, daPessoa: true);
        $agora = $this->relogio->now();

        try {
            match ($acao) {
                self::ACEITAR => $convite->aceitar($pessoa, $agora),
                self::RECUSAR => $convite->recusar($pessoa, $agora),
                self::DEIXAR => $this->deixar($convite, $pessoa, $agora),
                default => throw new \DomainException('Ação desconhecida.'),
            };
        } catch (\DomainException) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CONVITE_INDISPONIVEL);
        }
        $this->em->flush();

        return $convite;
    }

    /** Deixar de colaborar: só o aceito (o pendente se recusa). */
    private function deixar(PastaCompartilhamento $convite, User $pessoa, \DateTimeImmutable $agora): void
    {
        if ($convite->getStatus() !== EstadoDoCompartilhamento::Aceita) {
            throw new \DomainException('Só deixa quem colabora.');
        }
        $convite->encerrar($pessoa, $agora);
    }
}
