<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Para onde o aviso do sino leva quem o lê (dropdown e "Ver todas").
 *
 * Padrão de sempre: o link gravado; sem link, a meta (`/tarefas/<id>`); sem nenhum, `#`. No acesso restrito (spec
 * acesso-restrito-e-compartilhar, fatia 3) as telas de metas (`tarefa_*`) são "Fora do seu acesso": o aviso de meta
 * leva à aba Metas da pasta, já na meta (`/pasta/<id>?meta=<id>#tarefas`), e o aviso de meta sem meta própria
 * (menção no despacho, metas redistribuídas) fica sem link. A meta da pasta fora do escopo nem chega aqui: o sino a
 * corta na leitura.
 */
final class LinkDoAviso implements ResetInterface
{
    public const SEM_LINK = '#';
    private const TELAS_DE_METAS = '/tarefas';

    /** @var array<string, bool> */
    private array $restritos = [];

    public function __construct(
        private readonly EscopoDePastas $escopo,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function para(Notificacao $aviso, User $leitor): string
    {
        $meta   = $aviso->getTarefa();
        $padrao = $aviso->getUrl() ?? ($meta !== null ? self::TELAS_DE_METAS . '/' . $meta->getId() : self::SEM_LINK);
        if (!self::ehDasTelasDeMetas($padrao) || !$this->restrito($leitor, $aviso)) {
            return $padrao;
        }
        if ($meta === null) {
            return self::SEM_LINK;
        }

        return $this->urls->generate('pasta_show', ['id' => $meta->getPasta()->getId(), 'meta' => $meta->getId()]) . '#tarefas';
    }

    public function reset(): void
    {
        $this->restritos = [];
    }

    private static function ehDasTelasDeMetas(string $link): bool
    {
        $caminho = (string) parse_url($link, PHP_URL_PATH);

        return $caminho === self::TELAS_DE_METAS || str_starts_with($caminho, self::TELAS_DE_METAS . '/');
    }

    private function restrito(User $leitor, Notificacao $aviso): bool
    {
        $tenant = $aviso->getTenant();
        if ($tenant === null) {
            return false;
        }
        $chave = $leitor->getId() . '@' . $tenant->getId();

        return $this->restritos[$chave] ??= $this->escopo->ehRestritoNoEscritorio($leitor, $tenant);
    }
}
