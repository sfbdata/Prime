<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * Um escritório no conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 14.a): a linha da tabela do Master, na aba
 * "Configurações da I.A.", e o estado que o administrador do escritório vê ao ligar o conector.
 *
 * Escritório sem linha em `conector_ia_escritorio` sai desligado e sem aceite: é o padrão.
 */
final readonly class EscritorioNoConectorOutput
{
    /**
     * @param bool $chaveGeralLigada nível 1: a chave geral `conector_ia` do Master (interruptor de emergência)
     * @param bool $ligado        nível 2: o administrador ligou o conector
     * @param bool $aceiteVigente o aceite gravado cobre a versão vigente do texto
     *                            ({@see \App\Conector\Enum\VersaoDoAceiteDoConector::atual})
     * @param int  $conexoesAtivas consentimentos ativos no escritório; o Master vê só a contagem
     */
    public function __construct(
        public int $tenantId,
        public string $nome,
        public bool $chaveGeralLigada,
        public bool $ligado,
        public ?\DateTimeImmutable $aceiteEm,
        public bool $aceiteVigente,
        public int $conexoesAtivas,
    ) {
    }
}
