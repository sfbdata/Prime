<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Djen\Entity\PublicacaoDjen;

/**
 * Mapeia um item bruto da API do DJEN para os campos escalares de uma PublicacaoDjen, guardando o
 * item inteiro em payloadDjen (jsonb). Não decide tenant/OAB/processo — isso é do UseCase (contexto).
 * Espelha o DatajudProcessoMapper: helpers nullable com guarda is_scalar para não quebrar com arrays.
 */
final class DjenPublicacaoMapper
{
    public function mapearItem(PublicacaoDjen $publicacao, array $item): void
    {
        // Mesma normalização usada na chave de dedup (djenIdDoItem) — o valor gravado e a chave
        // precisam coincidir, senão a re-execução trataria o item como novo e violaria o unique.
        $publicacao->setDjenId(self::djenIdDoItem($item) ?? '0');
        $publicacao->setNumeroComunicacao($this->nullableInt($item['numeroComunicacao'] ?? null));
        $publicacao->setHash($this->nullableString($item['hash'] ?? null));
        $publicacao->setSiglaTribunal((string) ($this->nullableString($item['siglaTribunal'] ?? null) ?? ''));
        $publicacao->setTipoComunicacao($this->nullableString($item['tipoComunicacao'] ?? null));
        $publicacao->setNomeOrgao($this->nullableString($item['nomeOrgao'] ?? null));
        $publicacao->setNumeroProcesso(self::soDigitos((string) ($this->nullableString($item['numero_processo'] ?? null) ?? '')));
        $publicacao->setNumeroProcessoComMascara($this->nullableString($item['numeroprocessocommascara'] ?? null));
        $publicacao->setDataDisponibilizacao(
            $this->parseDate($item['data_disponibilizacao'] ?? $item['datadisponibilizacao'] ?? null)
        );
        $publicacao->setMeio($this->nullableString($item['meio'] ?? null));
        $publicacao->setMeioCompleto($this->nullableString($item['meiocompleto'] ?? null));
        $publicacao->setTexto($this->nullableString($item['texto'] ?? null));
        $publicacao->setLink($this->nullableString($item['link'] ?? null));
        $publicacao->setTipoDocumento($this->nullableString($item['tipoDocumento'] ?? null));
        $publicacao->setNomeClasse($this->nullableString($item['nomeClasse'] ?? null));
        $publicacao->setCodigoClasse($this->nullableString($item['codigoClasse'] ?? null));
        $publicacao->setPayloadDjen($item);
    }

    /**
     * Id da comunicação no DJEN (chave de deduplicação), ou null se ausente/ inválido.
     * Estático para o UseCase deduplicar em lote antes de instanciar entidades.
     */
    public static function djenIdDoItem(array $item): ?string
    {
        $id = $item['id'] ?? null;

        if (!is_scalar($id)) {
            return null;
        }

        $id = trim((string) $id);

        // Só dígitos e diferente de "0": um id ausente/inválido não deduplica (o item é ignorado no sync).
        return preg_match('/^\d+$/', $id) === 1 && $id !== '0' ? $id : null;
    }

    public static function soDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }

    private function nullableString(mixed $valor): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }

    /** Retorna o valor como string de dígitos (bigint), ou null. Não usa int nativo p/ evitar overflow. */
    private function nullableInt(mixed $valor): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return preg_match('/^\d+$/', $texto) === 1 ? $texto : null;
    }

    private function parseDate(mixed $valor): ?\DateTimeImmutable
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($valor, 0, 10));

        return $data === false ? null : $data;
    }
}
