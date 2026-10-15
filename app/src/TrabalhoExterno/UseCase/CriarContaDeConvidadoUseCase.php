<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Criar a conta de quem recebeu o convite e ainda não tem conta no BlueJus (D3, spec §8).
 *
 * Quem: anônimo com o link do convite. O e-mail da conta é o do convite (não é digitado).
 * Pré-condições: convite aceitável; nenhuma conta com esse e-mail (sem diferenciar caixa); nome;
 * senha de 8+ caracteres; aceite dos Termos.
 * Fluxo: cria o usuário (só `ROLE_USER`, SEM escritório), aceita o convite e registra o aceite do
 * termo sem escritório (o `AceiteTermo` aceita tenant nulo).
 * Pós-condição: a conta só enxerga a demanda do convite, como Convidado. Quem chama faz o login.
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
    ) {
    }

    public function executar(string $token, string $nome, string $senha, bool $aceiteTermos, ?string $ip, ?string $userAgent): User
    {
        $convite = $this->convites->peloToken($token) ?? throw new DemandaNaoEncontrada();
        $hoje = new \DateTimeImmutable('today');
        if (!$convite->aceitavel($hoje)) {
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
        if (!$aceiteTermos) {
            throw new DadosInvalidos('É necessário aceitar os Termos de Uso para criar a conta.');
        }

        $usuario = new User();
        $usuario->setEmail($convite->getEmail());
        $usuario->setFullName($nome);
        $usuario->setIsActive(true);
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setPassword($this->hasher->hashPassword($usuario, $senha));
        $this->em->persist($usuario);

        $convite->aceitar($usuario, $hoje);
        $this->em->flush();

        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(user: $usuario, ip: $ip ?? '', userAgent: $userAgent, tenant: null));

        return $usuario;
    }
}
