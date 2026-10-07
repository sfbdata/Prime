<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Preferencia\CatalogoDePreferenciasDaInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * Recolher ou expandir UMA análise do Push (IA-010, desenho dc L2448 e L5137-5139). Quem: o
 * usuário logado, para ele mesmo, no escritório da sessão. O quê: acrescenta ou retira o id da
 * lista `push.ia.recolhidas` dele em `preferencia_usuario`. Por quê: a escolha segue a pessoa em
 * qualquer computador (o protótipo usava `localStorage`; aqui é servidor).
 *
 * Guarda de IDOR ANTES de gravar: a análise tem de ser do escritório E da pasta da URL e não
 * estar excluída; senão {@see AnaliseNaoEncontradaException} (404) e nada é gravado. A análise
 * em si não muda (é preferência de tela, não estado da análise).
 *
 * Idempotente: recolher o que já está recolhido (ou expandir o que está aberto) regrava a mesma lista.
 */
final class RecolherAnaliseUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @return bool o estado gravado: true = recolhida */
    public function executar(int $analiseId, int $pastaId, bool $recolhida, User $user, Tenant $tenant): bool
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId);
        if ($analise === null || $analise->estaExcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $chave = CatalogoDePreferenciasDaInteligencia::PUSH_RECOLHIDAS;
        $atuais = CatalogoDePreferenciasDaInteligencia::recolhidasDe(
            $this->preferencias->valoresDoUsuario($tenant, $user, [$chave])[$chave] ?? [],
        );

        $this->preferencias->gravar(
            $tenant,
            $user,
            $chave,
            CatalogoDePreferenciasDaInteligencia::alternar($atuais, $analiseId, $recolhida),
        );

        return $recolhida;
    }
}
