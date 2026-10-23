<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Service\PermissionChecker;

/**
 * Quem recebe o aviso de alteração de uma pasta acompanhada ("Acompanhar alterações" do menu ⋮,
 * desenho "02 - EXPEDIENTES - VS 18.10.26", D:4447 e D:4493-4499).
 *
 * O padrão é LIGADO: acompanha a pasta todo usuário do escritório dela que
 *   - é usuário ativo e tem vínculo ATIVO com o escritório (o critério de quem é mencionável);
 *   - NÃO desligou o acompanhamento desta pasta (linha em `pasta_acompanhamento_desligado`);
 *   - VÊ a pasta: `canAccessResourceComVinculo` de `view`, o mesmo `canAccessResource` da
 *     `pasta_show`, das menções e do "Avisar quando chegar" do Push, com o vínculo já carregado; ela
 *     já aplica o cadeado como teto (Oculto não vê);
 *   - VÊ a aba de onde o evento vem ({@see VisibilidadeDasAbasDaPasta}, a regra lida uma vez por
 *     pasta): "Dados da pasta" para mensagem nova e situação (o aviso leva a um cartão do
 *     Registro), "Documentos" para documento novo.
 *
 * Saem também os que `$naoAvisar` indica: o autor da alteração e quem DE FATO recebeu um aviso
 * mais específico pela mesma ação (o dono do comentário respondido, se o aviso de resposta foi
 * criado, e os mencionados que foram notificados). Quem ficou sem o aviso próprio (cota estourada,
 * resposta já avisada) recebe este. Uma ação, um aviso.
 *
 * Eventos que avisam (decisão do dono, 23/10/2026; {@see AvisoDePastaAcompanhada}): mensagem nova
 * no Registro (`EnviarMensagemPastaUseCase`), documento novo (`UploadPecaUseCase` pelo upload da
 * pasta e `SalvarPecaTextoUseCase`) e troca de situação pela pílula (`DefinirSituacaoDaPastaUseCase`).
 * Meta e Push ficam de fora: já têm aviso próprio.
 *
 * PENDÊNCIA: não avisam a troca de responsável; o contrato enviado pelo Financeiro
 * (`pasta_financeiro_upload`, no controller legado); a cópia de documentos dentro da pasta; o
 * documento que chega pelo Drive (sem autor) e o anexo do despacho da meta (é evento da meta); e a
 * situação mudada pelo "Editar dados" (que não grava cartão no Registro). Cada um entra chamando o
 * aviso com os seus `$naoAvisar`.
 */
final class QuemAcompanhaAPasta
{
    public function __construct(
        private readonly PastaAcompanhamentoDesligadoRepository $desligados,
        private readonly PermissionChecker $permissionChecker,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    /**
     * CUSTO por evento (o que restou depois de juntar o que dava): uma consulta para os candidatos
     * (vínculo, usuário e perfil juntos), uma para a regra da aba do evento nesta pasta e, por
     * candidato que passa, a checagem de `view`: o nível no cadeado da pasta e o acesso por recurso
     * (`resource_access`), mais as permissões do perfil na primeira vez que cada perfil aparece. Só
     * quem a regra da aba alcança custa mais uma consulta (o vínculo de primário). O vínculo não é
     * buscado de novo: ele veio na consulta dos candidatos. Num escritório de N pessoas, a ordem é
     * de 2 consultas por pessoa; o aviso sai depois do commit da ação e não a atrasa nem a desfaz.
     *
     * @param list<User|null> $naoAvisar
     * @param AbaDaPasta      $aba       a aba de onde o evento vem: quem a tem oculta fica de fora
     *
     * @return list<User>
     */
    public function destinatarios(Pasta $pasta, Tenant $tenant, array $naoAvisar, AbaDaPasta $aba = AbaDaPasta::Dados): array
    {
        $donoDaPasta = $pasta->getTenant();
        $pastaId     = $pasta->getId();
        if ($pastaId === null || $donoDaPasta === null || !self::mesmoTenant($donoDaPasta, $tenant)) {
            return [];
        }

        $fora       = self::idsDe($naoAvisar);
        $candidatos = [];
        foreach ($this->desligados->vinculosQueNaoDesligaram($pasta, $tenant) as $vinculo) {
            if (!isset($fora[(int) $vinculo->getUser()->getId()])) {
                $candidatos[] = $vinculo;
            }
        }
        if ($candidatos === []) {
            return [];
        }

        // A regra da aba do evento é lida uma vez para todos.
        $semAAba = $this->visibilidadeDasAbas->quemNaoVeAAba(
            $pasta,
            $aba,
            array_map(static fn (UserTenant $v): User => $v->getUser(), $candidatos),
        );

        $destinatarios = [];
        foreach ($candidatos as $vinculo) {
            $pessoa = $vinculo->getUser();
            if (isset($semAAba[(int) $pessoa->getId()])) {
                continue;
            }
            if ($this->permissionChecker->canAccessResourceComVinculo($pessoa, $vinculo, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
                $destinatarios[] = $pessoa;
            }
        }

        return $destinatarios;
    }

    /**
     * @param list<User|null> $pessoas
     *
     * @return array<int, true>
     */
    private static function idsDe(array $pessoas): array
    {
        $ids = [];
        foreach ($pessoas as $pessoa) {
            if ($pessoa !== null && $pessoa->getId() !== null) {
                $ids[$pessoa->getId()] = true;
            }
        }

        return $ids;
    }

    private static function mesmoTenant(Tenant $a, Tenant $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
