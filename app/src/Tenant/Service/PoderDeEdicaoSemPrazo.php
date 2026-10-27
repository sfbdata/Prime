<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\PoderSemPrazo;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ponto único da edição sem prazo (spec `edicao-sem-prazo-master-primario.md` §4). Uma pergunta só: dado o usuário e o
 * escritório, quem é ele para os 8 pontos com prazo de edição (§2)?
 *
 * - {@see PoderSemPrazo::Master}: `ROLE_SUPER_ADMIN`, sempre, sem opção de desligar (§1.2);
 * - {@see PoderSemPrazo::Primario}: primário ativo DESTE escritório ({@see RegraDosPrimarios::ehPrimario}) com a opção
 *   do escritório ligada (§1.3; sem linha, ligada);
 * - `null`: os demais seguem a regra de sempre (autor, dentro do prazo).
 *
 * Cada ponto pergunta `(autor E dentro do prazo) OU poder !== null` por {@see libera()}, no servidor e na tela (a
 * extensão Twig usa a MESMA resposta). O poder tira só o prazo e a autoria: tenant, módulo, cadeado e pasta excluída
 * continuam com quem já os guarda. A resposta é guardada por pessoa e escritório durante a requisição (a lista de uma
 * pasta pergunta por item); quem troca a opção chama {@see esquecer()}.
 */
final class PoderDeEdicaoSemPrazo implements ResetInterface
{
    /** @var array<string, PoderSemPrazo|false> */
    private array $memoria = [];

    public function __construct(
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly UserTenantRepository $vinculos,
        private readonly ConfiguracaoDeEdicaoRepository $configuracoes,
    ) {
    }

    public function de(User $usuario, Tenant $tenant): ?PoderSemPrazo
    {
        $chave = ($usuario->getId() ?? 'o' . spl_object_id($usuario)) . ':' . ($tenant->getId() ?? 'o' . spl_object_id($tenant));
        $this->memoria[$chave] ??= $this->calcular($usuario, $tenant) ?? false;

        return $this->memoria[$chave] === false ? null : $this->memoria[$chave];
    }

    /** `(autor E dentro do prazo) OU poder`: a regra de sempre, mais o poder. */
    public function libera(User $usuario, Tenant $tenant, bool $pelaRegraDeSempre): bool
    {
        return $pelaRegraDeSempre || $this->de($usuario, $tenant) !== null;
    }

    /** O poder que liberou, só quando a regra de sempre não liberaria (é quando a tela mostra o selo, §5). */
    public function selo(User $usuario, Tenant $tenant, bool $pelaRegraDeSempre): ?PoderSemPrazo
    {
        return $pelaRegraDeSempre ? null : $this->de($usuario, $tenant);
    }

    /**
     * O autor do conteúdo tem vínculo ativo no escritório? O primário só mexe no que é da própria equipe (item 8 do TE:
     * fora ficam as mensagens da Central e dos convidados de outros escritórios).
     */
    public function temVinculoAtivo(User $autor, Tenant $tenant): bool
    {
        return $this->vinculos->findAtivoPorUserETenant($autor, $tenant) !== null;
    }

    public function esquecer(): void
    {
        $this->memoria = [];
    }

    public function reset(): void
    {
        $this->esquecer();
    }

    private function calcular(User $usuario, Tenant $tenant): ?PoderSemPrazo
    {
        if ($this->regraDosPrimarios->ehMaster($usuario)) {
            return PoderSemPrazo::Master;
        }
        $vinculo = $this->vinculos->findAtivoPorUserETenant($usuario, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            return null;
        }

        return $this->configuracoes->primarioSemPrazo($tenant) ? PoderSemPrazo::Primario : null;
    }
}
