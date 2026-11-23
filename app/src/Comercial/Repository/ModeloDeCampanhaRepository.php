<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\ModeloDeCampanha;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Os modelos próprios do Master (spec `comercial-e-emails.md` §8.2). Plataforma, sem escritório: só o Master chega
 * aqui (`ROLE_SUPER_ADMIN` no controller), então nenhuma consulta filtra por tenant.
 *
 * Gravar e excluir levam a linha manual do audit_log na mesma transação (R6): `modelo_new`, `modelo_upd` ou
 * `modelo_del`, com `changes` só `{nome, modo}` (nunca o conteúdo), mais `fixo_chave` na versão editada de um padrão (`modelo_upd` ao salvar, `modelo_pad` ao voltar ao padrão (o audit_log.action tem 10 caracteres)).
 *
 * @extends ServiceEntityRepository<ModeloDeCampanha>
 */
class ModeloDeCampanhaRepository extends ServiceEntityRepository
{
    public const NOVO = 'modelo_new';
    public const ALTERADO = 'modelo_upd';
    public const EXCLUIDO = 'modelo_del';
    public const VOLTOU_AO_PADRAO = 'modelo_pad';

    public function __construct(ManagerRegistry $registry, private readonly ?RequestStack $requisicoes = null)
    {
        parent::__construct($registry, ModeloDeCampanha::class);
    }

    /** @return list<ModeloDeCampanha> os próprios, do mais antigo ao mais novo (a ordem em que o dc acrescenta à grade) */
    public function todos(): array
    {
        return $this->findBy(['fixoChave' => null], ['id' => 'ASC']);
    }

    /** Só o modelo próprio: a linha da versão editada de um padrão não responde por id. */
    public function porId(int $id): ?ModeloDeCampanha
    {
        return $this->findOneBy(['id' => $id, 'fixoChave' => null]);
    }

    /** A versão do Master do modelo padrão `$chave`, se ele já foi editado. */
    public function doFixo(string $chave): ?ModeloDeCampanha
    {
        return $this->findOneBy(['fixoChave' => $chave]);
    }

    /** @return array<string, ModeloDeCampanha> as versões editadas dos padrões, por chave */
    public function editados(): array
    {
        $porChave = [];
        foreach ($this->createQueryBuilder('m')->where('m.fixoChave IS NOT NULL')->getQuery()->getResult() as $m) {
            $porChave[(string) $m->getFixoChave()] = $m;
        }

        return $porChave;
    }

    /** Grava o modelo e a linha da trilha na mesma transação. */
    public function salvar(ModeloDeCampanha $modelo, string $acao, User $por): void
    {
        $em = $this->getEntityManager();
        $rota = $this->rota();
        $em->wrapInTransaction(static function () use ($em, $modelo, $acao, $por, $rota): void {
            $em->persist($modelo);
            $em->flush();
            $em->persist(self::trilha($modelo, (string) $modelo->getId(), $acao, $por, $rota));
            $em->flush();
        });
    }

    /** Exclui o próprio (`modelo_del`) ou, na versão editada de um padrão, volta ao padrão (`modelo_pad`). */
    public function remover(ModeloDeCampanha $modelo, User $por, string $acao = self::EXCLUIDO): void
    {
        $em = $this->getEntityManager();
        $id = (string) $modelo->getId();
        $rota = $this->rota();
        $em->wrapInTransaction(static function () use ($em, $modelo, $id, $por, $rota, $acao): void {
            $em->remove($modelo);
            $em->persist(self::trilha($modelo, $id, $acao, $por, $rota));
            $em->flush();
        });
    }

    /**
     * A rota da requisição principal, como o `AuditLogSubscriber` grava nas linhas automáticas: na tela, a do controller;
     * pelo conector de I.A., `conector_mcp` (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisão 36). Fora de requisição, nula.
     */
    private function rota(): ?string
    {
        $rota = $this->requisicoes?->getMainRequest()?->attributes->get('_route');

        return \is_string($rota) ? $rota : null;
    }

    private static function trilha(ModeloDeCampanha $modelo, string $id, string $acao, User $por, ?string $rota): AuditLog
    {
        return (new AuditLog())
            ->setAction($acao)
            ->setRoute($rota)
            ->setEntityClass(ModeloDeCampanha::class)
            ->setEntityId($id)
            ->setActorUserId($por->getId())
            ->setActorEmail($por->getEmail())
            ->setChanges(['nome' => $modelo->getNome(), 'modo' => $modelo->getModo()] + ($modelo->getFixoChave() === null ? [] : ['fixo_chave' => $modelo->getFixoChave()]));
    }
}
