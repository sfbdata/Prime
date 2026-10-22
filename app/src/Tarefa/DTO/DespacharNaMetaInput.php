<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\NaturezaDoDespacho;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O que vem do bloco DESPACHO da meta (desenho 04B de 22/10, L57-66; spec `minhas-metas-esteira-2210.md`, C10 a C12).
 *
 * `texto` chega já normalizado ({@see \App\Tarefa\Service\TextoDoDespacho::normalizar()}): o limite de 5.000 conta o
 * texto puro que a pessoa escreveu, não o HTML guardado. `acao` é a do ciclo de vida da meta: vazia (Despachar),
 * `enviar` (Enviar para revisão) ou `devolver`. O anexo entra depois da validação ({@see comAnexo()}), para um
 * despacho recusado não deixar arquivo para trás.
 */
final class DespacharNaMetaInput
{
    public const LIMITE_DO_TEXTO = 5000;
    public const ACOES = ['', 'enviar', 'devolver'];

    public function __construct(
        #[Assert\Length(max: self::LIMITE_DO_TEXTO, maxMessage: 'O despacho pode ter até 5.000 caracteres.')]
        public readonly string $texto,
        #[Assert\Choice(choices: self::ACOES, message: 'Ação do despacho inválida.')]
        public readonly string $acao = '',
        #[Assert\Choice(callback: [self::class, 'naturezas'], message: 'Natureza do despacho inválida.')]
        public readonly ?string $natureza = null,
        #[Assert\Date(message: 'Prazo inválido.')]
        public readonly ?string $prazo = null,
        public readonly \DateTimeImmutable $hoje = new \DateTimeImmutable('today'),
        public readonly ?string $arquivoAnexo = null,
    ) {
    }

    /** @return list<string> */
    public static function naturezas(): array
    {
        return array_map(static fn (NaturezaDoDespacho $n): string => $n->value, NaturezaDoDespacho::cases());
    }

    #[Assert\IsTrue(message: 'O prazo para cumprimento não pode ser antes de hoje.')]
    public function isPrazoNaoNoPassado(): bool
    {
        $prazo = $this->prazoComoData();

        return $prazo === null || $prazo >= $this->hoje->setTime(0, 0);
    }

    public function prazoComoData(): ?\DateTimeImmutable
    {
        if ($this->prazo === null || $this->prazo === '') {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->prazo);

        return $data === false ? null : $data;
    }

    public function naturezaEscolhida(): ?NaturezaDoDespacho
    {
        return NaturezaDoDespacho::tryFrom((string) $this->natureza);
    }

    public function comAnexo(?string $caminho): self
    {
        return new self($this->texto, $this->acao, $this->natureza, $this->prazo, $this->hoje, $caminho);
    }
}
