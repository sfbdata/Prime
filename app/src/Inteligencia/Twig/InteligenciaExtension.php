<?php

declare(strict_types=1);

namespace App\Inteligencia\Twig;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Inteligencia\UseCase\ListarDocumentosDoIndiceUseCase;
use App\Inteligencia\UseCase\ObterAnalisesRecolhidasUseCase;
use App\Pasta\Entity\Pasta;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ia_disponibilidade()` — para a tela dizer o motivo certo no lugar do botão. Devolve o enum:
 * no Twig, `ia.estaDisponivel`, `ia.mensagem` e `ia.value` ('disponivel', 'sem_permissao', …).
 * Sem usuário/tenant na sessão responde `SemPermissao` (nenhuma página de pasta chega aqui assim).
 *
 * `ia_agentes()` — os sete agentes da pasta (enum `Agente`), para o drawer do cabeçalho
 * desenhar a lista sem depender do controller da pasta (legado, fora desta frente).
 *
 * `ia_comandos()` e `ia_relatorios()` — os 10 comandos (aba Análise) e os 5 relatórios (aba
 * Relatórios) do drawer (enum `Comando`, spec inteligencia-drawer-da-pasta F1/F2), pelo mesmo motivo.
 *
 * `ia_comando_indice()`: o "Índice inteligente" da aba Documentos (F4); `ia_comando_leitura()`: o "Ler N
 * documentos" dela (PR F). `ia_aba_documentos_visivel(pasta)`:
 * se a aba Documentos do drawer aparece para quem está logado; some quando a aba Documentos da pasta
 * está oculta para ele (spec pasta-ocultar-aba; a mesma regra que corta os documentos do contexto).
 *
 * `ia_push_recolhidas()` — ids das análises do Push que o usuário logado recolheu neste escritório
 * (IA-010, `preferencia_usuario`). Função, e não variável do controller, pelo mesmo motivo: o
 * fragmento da lista é incluído pelo `_push_processual.html.twig` com `only`. Sem sessão, lista vazia.
 */
final class InteligenciaExtension extends AbstractExtension
{
    public function __construct(
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly ObterAnalisesRecolhidasUseCase $recolhidas,
        private readonly VisibilidadeDaAnaliseDaPasta $visibilidadeDaAnalise,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ia_disponibilidade', $this->iaDisponibilidade(...)),
            new TwigFunction('ia_agentes', $this->iaAgentes(...)),
            new TwigFunction('ia_comandos', $this->iaComandos(...)),
            new TwigFunction('ia_relatorios', $this->iaRelatorios(...)),
            new TwigFunction('ia_comando_indice', static fn (): Comando => Comando::daAbaDocumentos()),
            new TwigFunction('ia_comando_leitura', static fn (): Comando => Comando::leituraDeDocumentos()),
            new TwigFunction('ia_aba_documentos_visivel', $this->iaAbaDocumentosVisivel(...)),
            new TwigFunction('ia_push_recolhidas', $this->iaPushRecolhidas(...)),
        ];
    }

    /** @return list<Agente> */
    public function iaAgentes(): array
    {
        return Agente::cases();
    }

    /** @return list<Comando> */
    public function iaComandos(): array
    {
        return Comando::comandos();
    }

    /** @return list<Comando> */
    public function iaRelatorios(): array
    {
        return Comando::relatorios();
    }

    public function iaAbaDocumentosVisivel(Pasta $pasta): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return !ListarDocumentosDoIndiceUseCase::abaDocumentosOculta($this->visibilidadeDaAnalise->abasOcultasPara($pasta, $user));
    }

    /** @return list<int> */
    public function iaPushRecolhidas(): array
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return [];
        }

        return $this->recolhidas->executar($tenant, $user);
    }

    public function iaDisponibilidade(): Disponibilidade
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return Disponibilidade::SemPermissao;
        }

        return $this->disponibilidade->para($user, $tenant);
    }
}
