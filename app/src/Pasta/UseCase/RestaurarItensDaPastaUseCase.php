<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Tira da LIXEIRA uma seleção de documentos e subpastas da pasta — o "Desfazer" do toast e a UI
 * da lixeira (D7, DOC-58).
 *
 * Quem dispara é quem pode EXCLUIR (permissão de editar a pasta — S-10, como a lápide da Pasta).
 * O que ele quer: o item de volta onde estava, com o que foi excluído junto.
 *
 * Regras:
 *  - cada item precisa estar NA LIXEIRA desta pasta e deste escritório (a rota prova por consulta;
 *    aqui é a segunda barreira). Item vivo na seleção é pedido inválido — não "já está restaurado";
 *  - uma subpasta volta com a subárvore que foi excluída JUNTO com ela (mesmo carimbo). O que
 *    estava na lixeira dentro dela com outro carimbo fica lá;
 *  - **pai ausente → raiz.** Se a seção de origem de um documento (ou o pai de uma subpasta) ainda
 *    está na lixeira e não faz parte desta restauração, o item volta para a RAIZ da pasta: um
 *    item vivo pendurado num pai invisível seria inalcançável na tela e faria o proxy do pai
 *    falhar ao carregar. "Pai purgado" não deixa item para trás — as FKs `secao_id`/`secao_pai_id`
 *    são `ON DELETE CASCADE`, a purga da seção leva as linhas de dentro;
 *  - ancestrais antes dos descendentes: se pai e filha estão na seleção, o pai volta primeiro (e
 *    a filha, se tiver o mesmo carimbo, volta com ele); processar a filha antes a mandaria para a
 *    raiz por "pai ainda na lixeira", e o pai restaurado em seguida ficaria sem ela.
 *
 * PRÉ-CONDIÇÃO (de quem chama): rodar DENTRO de `AcessoALixeira::comLixeiraVisivel()` — consulta,
 * travessia e `flush` —, com os itens carregados nesse estado. Com o filtro ligado as coleções
 * vêm sem a lixeira e a travessia não devolve o bloco; um proxy de pai excluído iniciado com o
 * filtro ligado lança `EntityNotFoundException`.
 *
 * Auditoria: cada volta é um `update` (diff de `excluidoEm`/`excluidoPor`, e `secao`/`pai` quando
 * foi para a raiz) pelo `AuditLogSubscriber`.
 */
final class RestaurarItensDaPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     *
     * @throws AccessDeniedException     item de outro escritório
     * @throws \InvalidArgumentException item de outra pasta, fora da lixeira, ou seleção vazia
     */
    public function executar(Pasta $pasta, array $documentos, array $secoes, User $autor, Tenant $tenant): ResultadoRestaurarItensDaPasta
    {
        if ($documentos === [] && $secoes === []) {
            throw new \InvalidArgumentException('Nenhum item selecionado.');
        }

        foreach ($secoes as $secao) {
            $this->conferir($secao, $secao->getPasta(), $secao->getTenant(), $pasta, $tenant);
        }
        foreach ($documentos as $documento) {
            $this->conferir($documento, $documento->getPasta(), $documento->getTenant(), $pasta, $tenant);
        }

        // Ancestrais primeiro (ver o docblock). `getProfundidade()` sobe por `getPai()`, que com o
        // filtro desligado carrega pais na lixeira normalmente.
        usort($secoes, static fn (PastaSecao $a, PastaSecao $b): int => $a->getProfundidade() <=> $b->getProfundidade());

        $totalDocumentos = 0;
        $totalSecoes     = 0;
        $paraARaiz       = 0;

        foreach ($secoes as $secao) {
            // Já voltou pela subárvore de um ancestral desta mesma seleção.
            if (!$secao->estaNaLixeira()) {
                continue;
            }

            $carimbo   = $secao->getExcluidoEm();
            $daArvore  = $secao->restaurarArvore($carimbo);
            $totalSecoes     += 1 + $daArvore['subpastas'];
            $totalDocumentos += $daArvore['arquivos'];

            $pai = $secao->getPai();
            if ($pai !== null && $pai->estaNaLixeira()) {
                $secao->setPai(null);
                ++$paraARaiz;
            }
        }

        foreach ($documentos as $documento) {
            if (!$documento->estaNaLixeira()) {
                continue;
            }

            $documento->restaurar();
            ++$totalDocumentos;

            $origem = $documento->getSecao();
            if ($origem !== null && $origem->estaNaLixeira()) {
                $documento->setSecao(null);
                ++$paraARaiz;
            }
        }

        $this->em->flush();

        return new ResultadoRestaurarItensDaPasta($totalDocumentos, $totalSecoes, $paraARaiz);
    }

    private function conferir(PastaDocumento|PastaSecao $item, ?Pasta $pastaDoItem, ?Tenant $tenantDoItem, Pasta $pasta, Tenant $tenant): void
    {
        if ($tenantDoItem !== $tenant) {
            throw new AccessDeniedException('Item não pertence ao tenant do usuário.');
        }
        if ($pastaDoItem !== $pasta) {
            throw new \InvalidArgumentException('O item selecionado não pertence a esta pasta.');
        }
        if (!$item->estaNaLixeira()) {
            throw new \InvalidArgumentException('O item selecionado não está na lixeira.');
        }
    }
}
