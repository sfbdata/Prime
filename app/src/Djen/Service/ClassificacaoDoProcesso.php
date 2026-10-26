<?php

declare(strict_types=1);

namespace App\Djen\Service;

/**
 * Áreas e fases que a pessoa escolhe para um processo acompanhado (desenho 11, `GRUPOS` e `FASES`).
 * São rótulos de organização do escritório, preenchidos à mão; o sistema não os deduz do tribunal.
 */
final class ClassificacaoDoProcesso
{
    public const AREAS = ['Cível', 'Família', 'Consumidor', 'Previdenciário', 'Trabalhista', 'Penal', 'Tributário', 'Administrativo', 'Condominial'];

    /** Fases por grupo, na ordem do desenho. */
    public const FASES = [
        'Judicial' => ['Ação protocolada', 'Com juiz para análise', 'Aguardando manifestação', 'Aguardando audiência', 'Aguardando julgamento', 'Sentença proferida', 'Trânsito em julgado'],
        'Recursal' => ['Desenvolvendo recurso', 'Recurso protocolado', 'Aguardando julgamento do recurso', 'Recurso julgado'],
        'Execução/cobrança' => ['Elaboração de cálculo', 'Citação para pagamento', 'Aguardando penhora', 'RPV emitido', 'Aguardando pagamento'],
        'Administrativo' => ['Requerimento protocolado', 'Atendendo exigências', 'Aguardando decisão do órgão'],
        'Consultoria' => ['Análise do caso', 'Elaboração de parecer'],
    ];

    /** @return list<string> */
    public static function todasAsFases(): array
    {
        return array_merge(...array_values(self::FASES));
    }

    public static function areaValida(?string $area): bool
    {
        return $area === null || $area === '' || \in_array($area, self::AREAS, true);
    }

    public static function faseValida(?string $fase): bool
    {
        return $fase === null || $fase === '' || \in_array($fase, self::todasAsFases(), true);
    }
}
