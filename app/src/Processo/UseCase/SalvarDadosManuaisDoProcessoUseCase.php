<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosDoProcessoInput;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\MontadorDosDadosDoProcesso;

/**
 * "Salvar" da edição do cartão do processo (P5b, docs/specs/processo-cartao-edicao.md; desenho
 * `salvar`). Quem: EDIT no processo, conferido pelo controller com o processo e a pasta do
 * escritório. O quê: só vira manual o valor preenchido e DIFERENTE do valor base (Datajud, ou o
 * valor da causa da pasta); vazio ou igual volta ao base. O "Não" manual no segredo de justiça é
 * recusado quando o tribunal informou sigilo (não desliga o bloqueio da IA nem do link externo).
 * Trilha: `dados_manuais_em/por` e uma linha no `audit_log` com antes e depois.
 *
 * Devolve em quantas pastas do escritório o processo está (o aviso depois de salvar).
 *
 * @throws DadosDoProcessoInvalidosException
 */
final class SalvarDadosManuaisDoProcessoUseCase
{
    public const MAXIMO_TEXTO = 255;
    public const MAXIMO_ASSUNTOS = 20;

    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly MontadorDosDadosDoProcesso $montador,
    ) {
    }

    public function executar(Processo $processo, Pasta $pasta, DadosDoProcessoInput $input, User $user, Tenant $tenant): int
    {
        $base = $this->montador->valoresBase($processo, $pasta);

        $classe = self::texto($input->textos['classe'] ?? '');
        $jurisdicao = self::texto($input->textos['jurisdicao'] ?? '');
        $orgao = self::texto($input->textos['orgao'] ?? '');
        $assuntos = self::assuntos($input->textos['assuntos'] ?? '');
        $distribuicao = self::data($input->textos['distribuicao'] ?? '');
        $valorCausa = self::valor($input->textos['valorCausa'] ?? '');
        $flags = $input->flags;

        if (($flags['segredo'] ?? null) === false && $base->segredo === true) {
            throw new DadosDoProcessoInvalidosException('O tribunal informou segredo de justiça neste processo. Mantenha "Sim" ou "Não informado".');
        }

        $novo = new DadosManuaisDoProcesso(
            classe: self::seDiferente($classe, $base->classe),
            assuntos: $assuntos !== null && !self::mesmaLista($assuntos, $base->assuntos) ? $assuntos : null,
            jurisdicao: self::seDiferente($jurisdicao, $base->jurisdicao),
            orgao: self::seDiferente($orgao, $base->orgao),
            distribuicao: $distribuicao !== null && $distribuicao->format('Y-m-d') !== $base->distribuicao?->format('Y-m-d') ? $distribuicao : null,
            valorCausa: $valorCausa !== null && !self::mesmoValor($valorCausa, $base->valorCausa) ? $valorCausa : null,
            segredo: self::flagSeDiferente($flags['segredo'] ?? null, $base->segredo),
            digital: self::flagSeDiferente($flags['digital'] ?? null, $base->digital),
            gratuita: self::flagSeDiferente($flags['gratuita'] ?? null, $base->gratuita),
            tutela: self::flagSeDiferente($flags['tutela'] ?? null, $base->tutela),
            prioridade: self::flagSeDiferente($flags['prioridade'] ?? null, $base->prioridade),
        );

        $antes = self::paraTrilha($processo->getDadosManuais());
        $processo->definirDadosManuais($novo, $user);

        $registro = (new AuditLog())
            ->setAction('update')
            ->setEntityClass(Processo::class)
            ->setEntityId((string) $processo->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($user->getId())
            ->setActorEmail($user->getEmail())
            ->setChanges([
                'dados_manuais' => ['from' => $antes, 'to' => self::paraTrilha($novo)],
                'pasta_id' => $pasta->getId(),
            ]);

        $this->processos->salvarComAuditoria($processo, $registro);

        return $this->processos->contarPastasDoProcesso($processo, $tenant);
    }

    /** Uma linha: controle, zero-width e bidi saem; espaços seguidos viram um. */
    private static function texto(string $texto): ?string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $texto)));
        if ($texto === '') {
            return null;
        }
        if (mb_strlen($texto) > self::MAXIMO_TEXTO) {
            throw new DadosDoProcessoInvalidosException('Texto longo demais.');
        }

        return $texto;
    }

    /** @return list<string>|null "Um assunto por linha" (desenho). */
    private static function assuntos(string $texto): ?array
    {
        $lista = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linha) {
            $linha = self::texto($linha);
            if ($linha !== null && !in_array($linha, $lista, true)) {
                $lista[] = $linha;
            }
        }
        if (count($lista) > self::MAXIMO_ASSUNTOS) {
            throw new DadosDoProcessoInvalidosException('Texto longo demais.');
        }

        return $lista !== [] ? $lista : null;
    }

    private static function data(string $texto): ?\DateTimeImmutable
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat('!d/m/Y', $texto);
        if ($data === false || $data->format('d/m/Y') !== $texto) {
            throw new DadosDoProcessoInvalidosException('Data inválida. Use dd/mm/aaaa.');
        }

        return $data;
    }

    /** "R$ 1.234,56" → "1234.56" (decimal em string, nunca float). */
    private static function valor(string $texto): ?string
    {
        $limpo = (string) preg_replace('/^R\$/u', '', (string) preg_replace('/\s+/u', '', $texto));
        if ($limpo === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,3}(\.\d{3})*|\d+)(,\d{1,2})?$/', $limpo)) {
            throw new DadosDoProcessoInvalidosException('Valor da causa inválido. Use R$ 0,00.');
        }
        [$inteiro, $centavos] = array_pad(explode(',', str_replace('.', '', $limpo)), 2, '0');
        $inteiro = ltrim($inteiro, '0') ?: '0';
        if (strlen($inteiro) > 13) {
            throw new DadosDoProcessoInvalidosException('Valor da causa inválido. Use R$ 0,00.');
        }

        return $inteiro . '.' . str_pad($centavos, 2, '0');
    }

    private static function seDiferente(?string $valor, ?string $base): ?string
    {
        if ($valor === null || ($base !== null && mb_strtolower($valor) === mb_strtolower($base))) {
            return null;
        }

        return $valor;
    }

    /**
     * @param list<string>      $lista
     * @param list<string>|null $base
     */
    private static function mesmaLista(array $lista, ?array $base): bool
    {
        return $base !== null && array_map('mb_strtolower', $lista) === array_map('mb_strtolower', $base);
    }

    private static function mesmoValor(string $valor, ?string $base): bool
    {
        if ($base === null) {
            return false;
        }
        [$inteiro, $centavos] = array_pad(explode('.', $base), 2, '0');

        return (ltrim($inteiro, '0') ?: '0') . '.' . str_pad(substr($centavos, 0, 2), 2, '0') === $valor;
    }

    private static function flagSeDiferente(?bool $valor, ?bool $base): ?bool
    {
        return $valor !== null && $valor !== $base ? $valor : null;
    }

    /** @return array<string, mixed> */
    private static function paraTrilha(DadosManuaisDoProcesso $dados): array
    {
        $saida = [];
        foreach (get_object_vars($dados) as $chave => $valor) {
            $saida[$chave] = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor;
        }

        return $saida;
    }
}
