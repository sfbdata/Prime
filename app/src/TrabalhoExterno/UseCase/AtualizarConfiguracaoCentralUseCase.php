<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Enum\Disponibilidade;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Visibilidade;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use App\TrabalhoExterno\Service\Contexto;

/**
 * Menu ⋮ "Opções do Master" e modal "Equipe e privacidade": visibilidade da aba, disponibilidade
 * da central, motivo da suspensão, permissões da Equipe, privacidade para externos e "aceitar só
 * rascunhos com a central suspensa", e a configuração interna das vagas (modal `tmCfgV`). Só o Master. Cada clique grava (o desenho não tem "Salvar").
 */
final class AtualizarConfiguracaoCentralUseCase
{
    public function __construct(
        private readonly ConfiguracaoCentralRepository $repositorio,
    ) {
    }

    /** @param array<string, mixed> $alteracoes */
    public function executar(Contexto $ctx, array $alteracoes): ConfiguracaoCentral
    {
        if ($ctx->perfil !== Perfil::Master) {
            throw new AcaoNaoPermitida('Só o Master altera a configuração da Central.');
        }

        $cfg = $this->repositorio->atual();

        try {
            if (array_key_exists('visibilidade', $alteracoes)) {
                $cfg->definirVisibilidade(Visibilidade::from((string) $alteracoes['visibilidade']));
            }
            if (array_key_exists('disponibilidade', $alteracoes)) {
                $cfg->definirDisponibilidade(Disponibilidade::from((string) $alteracoes['disponibilidade']));
            }
            if (array_key_exists('motivo', $alteracoes)) {
                $cfg->definirMotivo(is_string($alteracoes['motivo']) ? $alteracoes['motivo'] : null);
            }
            if (array_key_exists('bloquearSuspenso', $alteracoes)) {
                $cfg->definirBloquearSuspenso((bool) $alteracoes['bloquearSuspenso']);
            }
            foreach ((array) ($alteracoes['equipe'] ?? []) as $chave => $valor) {
                $cfg->definirEquipe((string) $chave, (bool) $valor);
            }
            foreach ((array) ($alteracoes['privacidade'] ?? []) as $chave => $valor) {
                $cfg->definirPrivacidade((string) $chave, (bool) $valor);
            }
            $cfg->definirTermoLigado((bool) ($alteracoes['termo'] ?? $cfg->termoLigado()));
            if (is_array($alteracoes['vagas'] ?? null)) {
                $cfg->definirVagas($alteracoes['vagas']);
            }
        } catch (\ValueError|\InvalidArgumentException) {
            throw new DadosInvalidos('Opção de configuração inválida.');
        }

        $this->repositorio->salvar($cfg, flush: true);

        return $cfg;
    }
}
