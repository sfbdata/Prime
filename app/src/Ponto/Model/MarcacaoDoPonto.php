<?php

declare(strict_types=1);

namespace App\Ponto\Model;

/**
 * Uma batida como a folha a conta (E10, plano-e10-ponto §6): a original sem ajuste ({@see \App\Ponto\Entity\RegistroPonto})
 * ou a cabeça de uma linhagem de ajustes ({@see BatidaAjustada}). Os leitores do Ponto trabalham com esta interface,
 * sem saber se a batida foi corrigida.
 */
interface MarcacaoDoPonto
{
    /** O id do registro original; nulo na batida incluída pelo gestor (que não tem registro). */
    public function getId(): ?int;

    public function getDataHora(): ?\DateTimeInterface;

    public function getTipo(): ?string;

    public function getObservacao(): ?string;

    public function isHomeOffice(): bool;

    public function isEnviadaSemInternet(): bool;

    /** A batida feita fora da cerca com o motivo do trabalho externo (E12); a ajustada guarda o da original. */
    public function isTrabalhoExterno(): bool;

    /** Batida feita pelo próprio colaborador (sem observação e sem a marca de lançamento manual); a ajustada, nunca. */
    public function feitaPeloColaborador(): bool;

    public function pessoaId(): int;

    /** O alvo dos ajustes: 'b{id do registro}' na batida gravada, 'a{id da inclusão}' na incluída pelo gestor. */
    public function alvo(): string;

    /** O id do ajuste que é a cabeça da linhagem; nulo na batida sem ajuste. */
    public function cabeca(): ?int;

    /** O ajuste que deu a forma atual (a cabeça); nulo na batida sem ajuste. */
    public function ajuste(): ?AjusteDaMarcacao;
}
