<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\PaginaDeEntradaOutput;
use App\Auth\Entity\AjusteEntrada;
use App\Auth\Repository\AjusteEntradaRepository;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;

/**
 * Lê os ajustes SÓ MASTER da tela de entrada e devolve o que a tela mostra (spec `login-desenho-10.md`).
 * Os padrões são os do desenho 10; um ajuste gravado substitui o padrão, e "Restaurar" apaga o ajuste.
 */
final class PaginaDeEntrada
{
    /** chave => [rótulo, ícone, endereço padrão], na ordem do rodapé do desenho. */
    public const LINKS = [
        'termos' => ['Termos', '', 'https://www.bluejus.com.br/termos/termos-de-uso.pdf'],
        'priv' => ['Privacidade', '', 'https://www.bluejus.com.br/politica-de-privacidade'],
        'linkedin' => ['LinkedIn', 'bi-linkedin', ''],
        'instagram' => ['Instagram', 'bi-instagram', ''],
    ];

    public function __construct(
        private readonly AjusteEntradaRepository $ajustes,
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function ehMaster(?User $user): bool
    {
        return $user !== null && in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    public function montar(?User $quemVe): PaginaDeEntradaOutput
    {
        $ajustes = $this->ajustes->todosPorChave();
        $master = self::ehMaster($quemVe);
        $links = $this->links($ajustes);
        $destinatarios = $master ? $this->destinatarios($ajustes) : [];

        return new PaginaDeEntradaOutput(
            ehMaster: $master,
            links: $master ? $links : array_values(array_filter($links, static fn (array $l): bool => !$l['oculto'] && $l['url'] !== '')),
            termosUrl: $this->urlDe('termos', $ajustes),
            privUrl: $this->urlDe('priv', $ajustes),
            destinatarios: $destinatarios,
            textoDestinatario: self::textoDoDestinatario($destinatarios),
        );
    }

    /**
     * Quem recebe as mensagens de suporte: o escolhido pelo Master, se ainda for Master ou ativo; senão, ninguém em
     * particular (nulo = todos os Masters).
     */
    public function destinatarioDoSuporte(): ?User
    {
        $ajuste = $this->ajustes->find(AjusteEntrada::SUPORTE_DESTINATARIO);
        $id = $ajuste?->getValor();
        if ($id === null || !ctype_digit($id)) {
            return null;
        }
        $user = $this->usuarios->find((int) $id);

        return $user !== null && $user->isActive() ? $user : null;
    }

    /**
     * Opções do "QUEM RECEBE AS MENSAGENS DE SUPORTE": os Masters primeiro, depois a equipe ativa do escritório
     * atual de quem vê (o desenho lista o Master e os usuários da conta dele).
     *
     * @return array<int, array{nome: string, cargo: string}> por id
     */
    public function opcoesDeDestinatario(): array
    {
        $opcoes = [];
        foreach ($this->usuarios->findComPapelGlobal('ROLE_SUPER_ADMIN') as $master) {
            if ($master->isActive() && $master->getId() !== null) {
                $opcoes[$master->getId()] = ['nome' => (string) $master->getFullName(), 'cargo' => 'Master'];
            }
        }
        $escritorio = $this->tenantContext->getCurrentTenant();
        foreach ($escritorio === null ? [] : $this->vinculos->findAtivosComPerfilPorTenant($escritorio) as $vinculo) {
            $user = $vinculo->getUser();
            if ($user->getId() !== null && !isset($opcoes[$user->getId()])) {
                $opcoes[$user->getId()] = ['nome' => (string) $user->getFullName(), 'cargo' => (string) $vinculo->getTenantRole()?->getName()];
            }
        }

        return $opcoes;
    }

    /**
     * @param array<string, AjusteEntrada> $ajustes
     *
     * @return list<array{chave: string, rotulo: string, icone: string, url: string, oculto: bool, padrao: string}>
     */
    private function links(array $ajustes): array
    {
        $links = [];
        foreach (self::LINKS as $chave => [$rotulo, $icone, $padrao]) {
            $links[] = [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'icone' => $icone,
                'url' => $this->urlDe($chave, $ajustes),
                'oculto' => ($ajustes[AjusteEntrada::chaveDoLink($chave)] ?? null)?->isOculto() ?? false,
                'padrao' => $padrao,
            ];
        }

        return $links;
    }

    /** @param array<string, AjusteEntrada> $ajustes */
    private function urlDe(string $chave, array $ajustes): string
    {
        $gravado = ($ajustes[AjusteEntrada::chaveDoLink($chave)] ?? null)?->getValor();

        return $gravado !== null && $gravado !== '' ? $gravado : self::LINKS[$chave][2];
    }

    /**
     * @param array<string, AjusteEntrada> $ajustes
     *
     * @return list<array{id: int, nome: string, cargo: string, escolhido: bool}>
     */
    private function destinatarios(array $ajustes): array
    {
        $opcoes = $this->opcoesDeDestinatario();
        $escolhido = $this->destinatarioDoSuporte()?->getId();
        if ($escolhido === null || !isset($opcoes[$escolhido])) {
            $escolhido = array_key_first($opcoes);
        }
        $lista = [];
        foreach ($opcoes as $id => $opcao) {
            $lista[] = ['id' => $id, 'nome' => $opcao['nome'], 'cargo' => $opcao['cargo'], 'escolhido' => $id === $escolhido];
        }

        return $lista;
    }

    /** O texto do desenho: "A partir de agora, <primeiro nome> recebe as mensagens no Chat I.A." */
    public static function textoDoDestinatario(array $destinatarios): string
    {
        foreach ($destinatarios as $d) {
            if ($d['escolhido']) {
                return 'A partir de agora, ' . explode(' ', trim($d['nome']))[0] . ' recebe as mensagens no Chat I.A.';
            }
        }

        return '';
    }
}
