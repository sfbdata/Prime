<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Auth\Service\ValidadorOab;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabInput;

/**
 * Criar ou editar uma inscrição na OAB da própria pessoa (desenho "07 - Meu Perfil", VS 18.10.26,
 * `_salvarOab`; spec docs/specs/meu-perfil-oab-inscricoes.md §1).
 *
 * Regras: número de 1 a 7 dígitos (o desenho aceita uma letra final, mas o banco exige só dígitos no espelho
 * User.oab_numero, chk_user_oab_numero; em produção, 19/10/2026, a maior tinha 5); UF de uma das 27 seccionais; no máximo uma
 * inscrição por UF; sempre exatamente uma principal (a primeira nasce principal; escolher outra rebaixa a
 * atual a suplementar; não dá para deixar a pessoa sem principal). Inscrição nova ou com número/UF
 * mudados volta a Pendente e a validação roda de novo na hora ({@see ValidadorOab::verificar()}, a mesma do cadastro):
 * se o CNA responder, o resultado fica registrado (Confirmada, ou Divergente se não achou o número ou o nome não
 * confere); se a consulta falhar (o CNA está dormente), fica Pendente para o Master. A principal é copiada para User.oab* (espelho). Toda recusa vem antes de qualquer
 * mudança; a troca de principal grava na ordem certa (InscricaoOabRepository::gravarComNovaPrincipal).
 *
 * @throws \DomainException com a mensagem do desenho
 */
final class SalvarInscricaoOabUseCase
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
        'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
        private readonly EspelhoDaOabPrincipal $espelho,
        private readonly ValidadorOab $validador,
    ) {
    }

    /**
     * @return StatusOab|null a situação depois da validação, quando a inscrição é nova ou teve número/UF mudados;
     *                        null quando nada mudou (mesmo número e UF: situação e data ficam)
     */
    public function executar(User $usuario, InscricaoOabInput $input): ?StatusOab
    {
        [$numero, $uf] = $this->exigirFormato($input);
        $todas         = $this->espelho->inscricoesDe($usuario);
        $alvo          = $this->localizar($todas, $input->id);
        $this->exigirUmaPorUf($todas, $alvo, $uf);
        $tipo = $todas === [] ? TipoInscricaoOab::Principal : $input->tipo;
        $this->exigirQueFiquePrincipal($alvo, $tipo);

        // Daqui em diante só gravação: toda recusa já aconteceu acima.
        $mudou = $alvo === null || $alvo->getNumero() !== $numero || $alvo->getUf() !== $uf;
        if ($alvo === null) {
            $alvo = new InscricaoOab($usuario, $numero, $uf, TipoInscricaoOab::Suplementar);
        } else {
            $alvo->alterar($numero, $uf);
        }
        if ($mudou) {
            $this->validarDeNovo($usuario, $alvo);
        }
        $this->gravar($usuario, $todas, $alvo, $tipo);

        return $mudou ? $alvo->getStatus() : null;
    }

    /** A consulta que falha não grava nada: a inscrição fica Pendente (o `alterar` já limpou situação e data). */
    private function validarDeNovo(User $usuario, InscricaoOab $alvo): void
    {
        $resultado = $this->validador->verificar($alvo->getNumero(), $alvo->getUf(), (string) $usuario->getFullName());
        if ($resultado->houveResposta()) {
            $alvo->registrarVerificacao($resultado->status, $resultado->nomeOficial);
        }
    }

    /** @param list<InscricaoOab> $todas */
    private function gravar(User $usuario, array $todas, InscricaoOab $alvo, TipoInscricaoOab $tipo): void
    {
        if ($tipo === TipoInscricaoOab::Principal && !$alvo->ehPrincipal()) {
            $outras = array_values(array_filter($todas, static fn (InscricaoOab $i): bool => $i !== $alvo && $i->ehPrincipal()));
            $this->inscricoes->gravarComNovaPrincipal($alvo, $outras);

            return;
        }
        if ($tipo === TipoInscricaoOab::Suplementar) {
            $alvo->tornarSuplementar();
        }
        $this->espelho->principalParaUsuario($usuario, in_array($alvo, $todas, true) ? $todas : [...$todas, $alvo]);
        $this->inscricoes->salvar($alvo, flush: true);
    }

    /** Rebaixar a única principal a suplementar deixaria a pessoa sem principal. */
    private function exigirQueFiquePrincipal(?InscricaoOab $alvo, TipoInscricaoOab $tipo): void
    {
        if ($alvo !== null && $alvo->ehPrincipal() && $tipo === TipoInscricaoOab::Suplementar) {
            throw new \DomainException('É preciso ter uma inscrição principal.');
        }
    }

    /** @return array{0: string, 1: string} */
    private function exigirFormato(InscricaoOabInput $input): array
    {
        // Pontos, traços, barras e espaços saem; letra ou outro caractere recusa (não some calado).
        $numero = preg_replace('/[\s.\-\/]/', '', $input->numero) ?? '';
        $uf     = strtoupper(trim($input->uf));
        if (preg_match('/^\d{1,7}$/', $numero) !== 1) {
            throw new \DomainException('Informe o número da OAB (somente números).');
        }
        if (!in_array($uf, self::UFS, true)) {
            throw new \DomainException('Escolha a UF da seccional.');
        }

        return [$numero, $uf];
    }

    /** @param list<InscricaoOab> $todas */
    private function localizar(array $todas, ?int $id): ?InscricaoOab
    {
        if ($id === null) {
            return null;
        }
        foreach ($todas as $inscricao) {
            if ($inscricao->getId() === $id) {
                return $inscricao;
            }
        }

        throw new \DomainException('Inscrição não encontrada.');
    }

    /** @param list<InscricaoOab> $todas */
    private function exigirUmaPorUf(array $todas, ?InscricaoOab $alvo, string $uf): void
    {
        foreach ($todas as $inscricao) {
            if ($inscricao !== $alvo && $inscricao->getUf() === $uf) {
                throw new \DomainException('Já existe uma inscrição na OAB/' . $uf . '. Cada seccional tem uma só inscrição por advogado.');
            }
        }
    }
}
