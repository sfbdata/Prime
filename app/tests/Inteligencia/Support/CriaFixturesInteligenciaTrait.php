<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Cliente\Entity\ClientePF;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPagamento;
use App\Processo\Entity\Processo;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures da BlueJus IA por cima das do Push da pasta (escritório, usuário, pasta, processo,
 * publicação): liga a IA no escritório, cria usuário com permissões específicas, cria análises.
 */
trait CriaFixturesInteligenciaTrait
{
    use CriaFixturesPushDaPastaTrait;

    private function ligarIaNoTenant(
        Tenant $tenant,
        User $por,
        int $limiteDiario = 50,
        int $limiteMensal = 500,
        bool $mascarar = true,
    ): ConfiguracaoDeInteligencia {
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->atualizar(true, $limiteDiario, $limiteMensal, $mascarar, $por);
        $this->em()->persist($configuracao);
        $this->em()->flush();

        return $configuracao;
    }

    /**
     * Papel comum com EXATAMENTE as permissões informadas (cria o código no catálogo se faltar).
     *
     * @param list<string> $codigos
     */
    private function criarUsuarioComPermissoes(Tenant $tenant, array $codigos, string $rotulo = 'ia'): User
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . $rotulo . ' ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }

            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $user = new User();
        $user->setEmail($rotulo . '_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $rotulo);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /**
     * Pasta com um processo vinculado e uma publicação do DJEN com o texto informado.
     *
     * @return array{Pasta, Processo, PublicacaoDjen}
     */
    private function criarPastaComPublicacao(
        Tenant $tenant,
        string $numero = '07011345720258070007',
        string $texto = 'Intimação da sentença. Prazo de 15 dias para apelação.',
    ): array {
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, $numero);
        $this->vincular($pasta, $processo);
        $publicacao = $this->criarPublicacao($tenant, $this->proximoDjenId(), $numero, '2026-10-01', $processo);
        $publicacao->setTexto($texto);
        $this->em()->flush();

        return [$pasta, $processo, $publicacao];
    }

    /** `djen_id` é BIGINT: precisa ser numérico e único por escritório. */
    private function proximoDjenId(): string
    {
        return (string) random_int(100_000_000, 999_999_999_999);
    }

    /** @param list<string> $chaves */
    private function criarAnalisePendente(Tenant $tenant, ?User $solicitante, Pasta $pasta, array $chaves = []): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $solicitante,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: (int) $pasta->getId(),
            versaoDoPrompt: PromptResumoDoPush::VERSAO,
            contextoHash: hash('sha256', implode('|', $chaves) . uniqid()),
            contextoResumo: ['chaves' => $chaves, 'publicacoes' => [], 'movimentacoes' => [], 'total' => count($chaves), 'novas' => count($chaves), 'processos' => []],
        );
        $this->em()->persist($analise);
        $this->em()->flush();

        return $analise;
    }

    /**
     * Análise pendente de UM agente da pasta (fatia 2), com a decisão sobre o financeiro já gravada
     * em `contexto_resumo.financeiro`, como o UseCase faz.
     */
    private function criarAnaliseDoAgentePendente(
        Tenant $tenant,
        ?User $solicitante,
        Pasta $pasta,
        Agente $agente = Agente::Gestor,
        bool $financeiro = true,
    ): AnaliseDeInteligencia {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $solicitante,
            tipo: TipoDeAnalise::AnalisePasta,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: (int) $pasta->getId(),
            versaoDoPrompt: PromptDoAgente::VERSAO,
            contextoHash: hash('sha256', $agente->value . uniqid()),
            contextoResumo: ['agente' => $agente->value, 'secoes' => [], 'omitidas' => [], 'total' => 0, 'processos' => [], 'financeiro' => $financeiro],
            agente: $agente,
        );
        $this->em()->persist($analise);
        $this->em()->flush();

        return $analise;
    }

    private function criarMeta(Tenant $tenant, Pasta $pasta, User $responsavel, string $titulo, string $prazo = '+3 days', string $descricao = ''): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTenant($tenant);
        $tarefa->setPasta($pasta);
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao($descricao);
        $tarefa->setPrazo(new \DateTimeImmutable($prazo));
        $tarefa->addResponsavel($responsavel);
        $this->em()->persist($tarefa);
        $this->em()->flush();

        return $tarefa;
    }

    private function criarAnotacao(Tenant $tenant, Pasta $pasta, User $autor, string $conteudo): PastaMensagem
    {
        $mensagem = new PastaMensagem();
        $mensagem->setTenant($tenant);
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($autor);
        $mensagem->setConteudo($conteudo);
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
    }

    private function criarDocumento(Tenant $tenant, Pasta $pasta, string $titulo, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $documento = new PastaDocumento();
        $documento->setTenant($tenant);
        $documento->setPasta($pasta);
        $documento->setTitulo($titulo);
        $documento->setCategoria($categoria);
        $documento->setCaminhoArquivo('teste/' . uniqid() . '.pdf');
        $documento->setNomeOriginal(strtolower(str_replace(' ', '-', $titulo)) . '.pdf');
        $documento->setMimeType('application/pdf');
        $documento->setTamanhoBytes(1234);
        $this->em()->persist($documento);
        $this->em()->flush();

        return $documento;
    }

    private function criarPagamento(Tenant $tenant, Pasta $pasta, string $descricao, string $valor = '1500.00'): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setPasta($pasta);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('+10 days'));
        $this->em()->persist($pagamento);
        $this->em()->flush();

        return $pagamento;
    }

    /** Cliente PF vinculado à pasta — com CPF, e-mail e telefone, que NUNCA podem sair no prompt. */
    private function criarClientePF(Tenant $tenant, Pasta $pasta, string $nome, string $cpf = '123.456.789-09'): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf($cpf);
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $cliente->setEmail('cliente_' . uniqid() . '@test.com');
        $cliente->setTelefoneCelular('(61) 99999-1234');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('Rua A, 1');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        return $cliente;
    }

    /** @param list<array{tipo: string, texto: string}> $pontos */
    private function concluirAnalise(
        AnaliseDeInteligencia $analise,
        string $resumo = 'Resumo de teste.',
        array $pontos = [],
        ?string $quem = null,
        ?string $textoDaAnalise = null,
    ): void {
        $analise->iniciarProcessamento();
        $analise->concluir(
            resumo: $resumo,
            pontos: $pontos,
            quemAge: $quem,
            textoBruto: (string) json_encode(['resumo' => $resumo, 'pontos' => $pontos, 'quem' => $quem, 'texto' => $textoDaAnalise]),
            provedor: ProvedorFalso::NOME,
            modelo: ProvedorFalso::MODELO,
            tokensEntrada: 100,
            tokensSaida: 30,
            duracaoMs: 12,
            textoDaAnalise: $textoDaAnalise,
        );
        $this->em()->flush();
    }

    private function provedorFalso(): ProvedorFalso
    {
        return static::getContainer()->get(ProvedorFalso::class);
    }

    private function transporteAsync(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /**
     * Conta por SQL cru, fora do alcance do TenantFilter e do ORM — para as perguntas "isto ainda
     * existe no banco?" que a exclusão suave precisa responder.
     *
     * @param array<string, mixed> $parametros
     */
    private function contarNoBanco(string $sql, array $parametros = []): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, $parametros);
    }
}
