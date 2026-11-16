<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;

/**
 * Formulário "Nova assembleia" / "Rascunho: {nome}": o título (nome do evento) e o peso do voto ("Fração ideal" ou
 * "Uma unidade, um voto"), mais o nome da entidade e o CNPJ (ambos opcionais; o CNPJ chega com ou sem máscara e o
 * UseCase grava só os dígitos), o cenário (tipo de entidade; padrão condomínio) e a marca de votação de simulação (só em rascunho). Sem data: no modo mesa o desenho não mostra Início/Encerramento.
 */
final readonly class AssembleiaInput
{
    public function __construct(
        public string $titulo,
        public string $peso,
        public string $entidade = '',
        public string $cnpj = '',
        public string $cenario = 'condominio',
        public bool $simulacao = false,
    ) {
    }

    public function pesoDoVoto(): PesoDoVoto
    {
        return PesoDoVoto::tryFrom($this->peso)
            ?? throw new OperacaoInvalidaNaAssembleiaException('Escolha o peso do voto.');
    }

    public function cenarioDaAssembleia(): CenarioDaAssembleia
    {
        return CenarioDaAssembleia::tryFrom($this->cenario)
            ?? throw new OperacaoInvalidaNaAssembleiaException('Escolha o tipo de entidade.');
    }
}
