<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Enum\ChaveDoAvisoDoPush;

/**
 * O cartão "Avisar quando chegar" da coluna do Push (desenho 1.2.3, dc L.2629-2636 e L.5447;
 * spec push-avisar-quando-chegar §2), já resolvido: as três linhas na ordem do desenho.
 *
 *   - "Por e-mail" só vale com "Responsável da pasta" ligado: sem ele, travado com a dica;
 *   - "Análise da BlueJus IA" só com a IA na plataforma, ligada no escritório e com o responsável
 *     da pasta podendo usá-la: senão, travado com o motivo real (DisponibilidadeDaIaNoAvisoDoPush).
 */
final readonly class AvisosDoPushOutput
{
    public const DICA_EMAIL_SEM_RESPONSAVEL = 'Ligue o aviso ao responsável para receber por e-mail.';

    /** @param list<ItemDoAvisoDoPushOutput> $itens */
    private function __construct(
        public array $itens,
    ) {
    }

    public static function montar(ChavesDoAvisoDoPush $chaves, ?string $motivoDaIa): self
    {
        $itens = [];
        foreach (ChaveDoAvisoDoPush::cases() as $chave) {
            $motivo = self::motivoDaTrava($chave, $chaves, $motivoDaIa);
            $itens[] = new ItemDoAvisoDoPushOutput($chave->value, $chave->rotulo(), $chaves->ligada($chave), $motivo !== null, $motivo);
        }

        return new self($itens);
    }

    /** O motivo de a chave não poder mudar agora, ou null. */
    public static function motivoDaTrava(ChaveDoAvisoDoPush $chave, ChavesDoAvisoDoPush $chaves, ?string $motivoDaIa): ?string
    {
        return match ($chave) {
            ChaveDoAvisoDoPush::Responsavel => null,
            ChaveDoAvisoDoPush::Email => $chaves->responsavel ? null : self::DICA_EMAIL_SEM_RESPONSAVEL,
            ChaveDoAvisoDoPush::Ia => $motivoDaIa,
        };
    }

    /** @return list<array{chave: string, ligado: bool, travado: bool, dica: ?string}> */
    public function paraJson(): array
    {
        return array_map(static fn (ItemDoAvisoDoPushOutput $item): array => $item->paraJson(), $this->itens);
    }
}
