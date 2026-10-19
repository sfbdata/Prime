<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Inteligencia\Enum\Disponibilidade;
use App\Pasta\Enum\ChaveDoAvisoDoPush;

/**
 * O cartão "Avisar quando chegar" da coluna do Push (desenho 1.2.3, dc L.2629-2636 e L.5447;
 * spec push-avisar-quando-chegar §2), já resolvido: as três linhas na ordem do desenho.
 *
 *   - "Por e-mail" só vale com "Responsável da pasta" ligado: sem ele, travado com a dica;
 *   - "Análise da BlueJus IA" só com a IA na plataforma e ligada no escritório: senão, travado
 *     com o motivo real (`Disponibilidade::mensagem()`).
 */
final readonly class AvisosDoPushOutput
{
    public const DICA_EMAIL_SEM_RESPONSAVEL = 'Ligue o aviso ao responsável para receber por e-mail.';

    /** @param list<ItemDoAvisoDoPushOutput> $itens */
    private function __construct(
        public array $itens,
    ) {
    }

    public static function montar(ChavesDoAvisoDoPush $chaves, Disponibilidade $ia): self
    {
        $itens = [];
        foreach (ChaveDoAvisoDoPush::cases() as $chave) {
            $motivo = self::motivoDaTrava($chave, $chaves, $ia);
            $itens[] = new ItemDoAvisoDoPushOutput($chave->value, $chave->rotulo(), $chaves->ligada($chave), $motivo !== null, $motivo);
        }

        return new self($itens);
    }

    /** O motivo de a chave não poder mudar agora, ou null. */
    public static function motivoDaTrava(ChaveDoAvisoDoPush $chave, ChavesDoAvisoDoPush $chaves, Disponibilidade $ia): ?string
    {
        return match ($chave) {
            ChaveDoAvisoDoPush::Responsavel => null,
            ChaveDoAvisoDoPush::Email => $chaves->responsavel ? null : self::DICA_EMAIL_SEM_RESPONSAVEL,
            ChaveDoAvisoDoPush::Ia => $ia->estaDisponivel() ? null : $ia->mensagem() . '.',
        };
    }

    /** @return list<array{chave: string, ligado: bool, travado: bool, dica: ?string}> */
    public function paraJson(): array
    {
        return array_map(static fn (ItemDoAvisoDoPushOutput $item): array => $item->paraJson(), $this->itens);
    }
}
