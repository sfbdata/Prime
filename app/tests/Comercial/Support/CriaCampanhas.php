<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Support;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cadastrados e campanhas para os testes da 2b, com o kernel de pé: usuários com vínculo (primário = papel de sistema)
 * e campanhas gravadas pelo repositório, como a confirmação grava (sem passar pela tela).
 */
trait CriaCampanhas
{
    private function usuario(string $prefixo = 'camp', bool $ativo = true, ?string $nome = 'Pessoa Teste'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($prefixo . '_' . uniqid() . '@teste.com')->setFullName((string) $nome)->setIsActive($ativo)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** O vínculo do usuário num escritório (novo, se não vier um); `primario` = o papel de sistema. */
    private function vincular(User $user, bool $primario, ?Tenant $tenant = null, bool $vinculoAtivo = true): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($tenant === null) {
            $tenant = (new Tenant())->setName('Escritório Campanha ' . uniqid())->setIsActive(true);
            $em->persist($tenant);
        }
        $papel = (new TenantRole())->setTenant($tenant)->setName($primario ? 'Administrador' : 'Equipe')->setIsSystem($primario);
        $em->persist($papel);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
        if (!$vinculoAtivo) {
            $em->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE id = ?', [$vinculo->getId()]);
        }

        return $tenant;
    }

    /**
     * Uma campanha gravada como a confirmação grava. Por padrão: Individual, para os e-mails dados, na fila agora.
     *
     * @param list<string>          $emails
     * @param array<string, mixed>  $troca  campos de {@see CampanhaDeEmailRepository::inserir()}
     */
    private function campanha(array $emails, array $troca = []): int
    {
        $c = static::getContainer();
        $mensagem = MensagemDaCampanha::deArray((array) (new ModelosDeCampanha())->fixo('convite'));
        $agora = new \DateTimeImmutable();
        $id = $c->get(CampanhaDeEmailRepository::class)->inserir(array_merge([
            'chave' => bin2hex(random_bytes(16)), 'assunto' => $mensagem->assunto, 'modelo_chave' => $mensagem->chave,
            'modelo_nome' => 'Convite', 'modo' => $mensagem->modo, 'selo' => $mensagem->selo, 'titulo' => $mensagem->titulo,
            'conteudo' => $c->get(ComposicaoDaCampanha::class)->congelar($mensagem), 'publico' => 'indiv',
            'parametros' => array_map(static fn (string $e): array => ['email' => $e, 'nome' => 'Nome ' . $e], $emails),
            'frequencia' => 'unica', 'agendada_para' => null, 'proxima' => $agora, 'estado' => 'na_fila', 'total' => \count($emails),
            'criada_por' => null, 'agora' => $agora,
        ], $troca));
        \assert(\is_int($id));

        return $id;
    }
}
