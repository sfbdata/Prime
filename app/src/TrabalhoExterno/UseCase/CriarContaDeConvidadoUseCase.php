<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\PoliticaDeCadastro;
use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Repository\UserRepository;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Service\ConviteDisponivel;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Criar a conta de quem recebeu o convite e ainda não tem conta no BlueJus (D3, spec §8).
 *
 * Quem: anônimo com o link do convite. O e-mail da conta é o do convite (não é digitado).
 * Pré-condições: convite disponível ({@see ConviteDisponivel}: aceitável e escritório da demanda no ar); nenhuma conta com esse e-mail (sem diferenciar caixa); nome;
 * senha de 8+ caracteres; gênero Feminino ou Masculino (spec genero-no-cadastro); aceite dos Termos.
 * Fluxo: cria o usuário (só `ROLE_USER`, SEM escritório), aceita o convite e registra o aceite do
 * termo sem escritório (o `AceiteTermo` aceita tenant nulo).
 * Pós-condição: a conta só enxerga a demanda do convite, como Convidado. Quem chama faz o login.
 * Cadastro fechado (`off`, spec `login-config-cadastro.md`): não nasce conta nova por aqui; lança CadastroNaoDisponivel.
 */
final class CriarContaDeConvidadoUseCase
{
    public const SENHA_MINIMA = 8;

    public function __construct(
        private readonly ConviteDemandaRepository $convites,
        private readonly UserRepository $usuarios,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
        private readonly EntityManagerInterface $em,
        private readonly ConviteDisponivel $disponivel,
        private readonly PoliticaDeCadastro $politicaDeCadastro,
    ) {
    }

    public function executar(string $token, string $nome, string $senha, bool $aceiteTermos, ?string $ip, ?string $userAgent, string $genero = ''): User
    {
        $this->politicaDeCadastro->exigirConviteDeContaNova();
        $convite = $this->convites->peloToken($token) ?? throw new DemandaNaoEncontrada();
        $hoje = new \DateTimeImmutable('today');
        if (!$this->disponivel->para($convite, $hoje)) {
            throw new DadosInvalidos('Este convite não está mais disponível.');
        }
        if ($this->usuarios->encontrarPorEmailIgnorandoCaixa($convite->getEmail()) !== []) {
            throw new DadosInvalidos('Já existe uma conta com este e-mail. Entre com ela para aceitar o convite.');
        }

        $nome = mb_substr(trim($nome), 0, 255);
        if ($nome === '') {
            throw new DadosInvalidos('Informe o nome completo.');
        }
        if (mb_strlen($senha) < self::SENHA_MINIMA) {
            throw new DadosInvalidos(sprintf('A senha precisa ter pelo menos %d caracteres.', self::SENHA_MINIMA));
        }
        if (Genero::tryFrom($genero)?->preenchido() !== true) {
            throw new DadosInvalidos(Genero::ERRO_INFORME);
        }
        if (!$aceiteTermos) {
            throw new DadosInvalidos('É necessário aceitar os Termos de Uso para criar a conta.');
        }

        $usuario = new User();
        $usuario->setEmail($convite->getEmail());
        $usuario->setFullName($nome);
        $usuario->setIsActive(true);
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setPassword($this->hasher->hashPassword($usuario, $senha));
        UserProfile::daContaNova($usuario, Genero::from($genero));   // persistido em cascata com o User
        $this->em->persist($usuario);

        $convite->aceitar($usuario, $hoje);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new DadosInvalidos('Já existe uma conta com este e-mail. Entre com ela para aceitar o convite.');
        }

        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(user: $usuario, ip: $ip ?? '', userAgent: $userAgent, tenant: null));

        return $usuario;
    }
}
