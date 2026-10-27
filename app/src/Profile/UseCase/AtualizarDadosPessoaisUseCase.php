<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Entity\Auth\UserTenant;
use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Entity\VinculoNoEscritorio;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\Repository\VinculoNoEscritorioRepository;

/**
 * "Editar informações" do Meu Perfil (desenho "07 - Meu Perfil", VS 18.10.26; spec
 * docs/specs/meu-perfil-pagina.md §2). Quem: a própria pessoa, no escritório da sessão.
 *
 * - Dados da pessoa (nome, frase, telefone, CPF, nascimento, gênero, atuação, CTPS, série) vão para o UserProfile.
 *   Gênero e atuação são só declarados pela pessoa (spec docs/specs/meu-perfil-genero-atuacao.md).
 * - O TIPO de vínculo só muda se `$podeAlterarVinculo` (primário do escritório ou Master); para os
 *   demais o que vier no formulário é ignorado e o tipo gravado continua.
 * - As datas valem conforme o tipo: CLT grava a admissão no UserTenant (o espelho de ponto lê
 *   dali); os demais gravam o início do contrato; PJ grava também o CNPJ. Trocar de tipo não apaga
 *   o que foi gravado para o outro. Tudo auditado (UserTenant e VinculoNoEscritorio são Auditavel).
 * - Sem escritório na sessão (Master solto), só os dados da pessoa.
 */
final class AtualizarDadosPessoaisUseCase
{
    public function __construct(
        private readonly UserProfileRepository $repository,
        private readonly VinculoNoEscritorioRepository $vinculos,
    ) {
    }

    public function executar(UserProfile $perfil, DadosPessoaisInput $input, ?UserTenant $vinculoAtual = null, bool $podeAlterarVinculo = false): void
    {
        $this->gravarDadosDaPessoa($perfil, $input);
        if ($vinculoAtual !== null) {
            $this->gravarVinculo($vinculoAtual, $input, $podeAlterarVinculo);
        }

        $this->repository->salvar($perfil, flush: true);
    }

    private function gravarDadosDaPessoa(UserProfile $perfil, DadosPessoaisInput $input): void
    {
        $perfil->setNomeCompleto(trim((string) $input->nomeCompleto));
        $perfil->setStatus(self::textoOuNulo($input->status));
        $perfil->setTelefone($input->telefone);
        $perfil->setChavePixRecompensa($input->chavePixRecompensa);
        // Como digitado (o espelho de ponto imprime o CPF gravado); a tela formata ao exibir.
        $perfil->setCpf(self::textoOuNulo($input->cpf));
        $perfil->setDataNascimento($input->dataNascimento);
        $perfil->setGenero($input->genero);
        $perfil->setAtuacao($input->atuacao);
        $perfil->setCtps(self::textoOuNulo($input->ctps));
        $perfil->setSerie(self::textoOuNulo($input->serie));
    }

    private function gravarVinculo(UserTenant $userTenant, DadosPessoaisInput $input, bool $podeAlterar): void
    {
        $vinculo = $this->vinculos->doUsuarioNoEscritorio($userTenant->getTenant(), $userTenant->getUser())
            ?? new VinculoNoEscritorio($userTenant->getTenant(), $userTenant->getUser());

        if ($podeAlterar && $input->vinculo !== null) {
            $vinculo->definirTipo($input->vinculo);
        }

        $tipo = $vinculo->getTipo();
        if ($tipo === VinculoColaborador::Clt) {
            $userTenant->setDataAdmissao(self::imutavel($input->dataAdmissao));
        } else {
            $vinculo->definirContratoInicio(self::imutavel($input->contratoInicio));
        }
        if ($tipo === VinculoColaborador::Pj) {
            $vinculo->definirCnpj($input->cnpj);
        }

        $this->vinculos->salvar($vinculo);
    }

    private static function textoOuNulo(?string $texto): ?string
    {
        $limpo = trim((string) $texto);

        return $limpo === '' ? null : $limpo;
    }

    private static function imutavel(?\DateTimeInterface $data): ?\DateTimeImmutable
    {
        return $data === null ? null : \DateTimeImmutable::createFromInterface($data);
    }
}
