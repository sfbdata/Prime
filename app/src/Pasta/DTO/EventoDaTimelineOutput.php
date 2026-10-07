<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Um acontecimento da Timeline inteligente da pasta (desenho 1.2.3, `bluejus-central.js` `tlEventos`).
 *
 * Cada evento nasce de UMA fonte real — mensagem da pasta, linha do audit_log, publicação do Push,
 * meta ou pagamento. `tipo` diz qual (é a chave que as regras de marco leem; nunca o título, que é
 * texto de tela). `categoria` é o agrupamento do desenho (chips e ícone).
 *
 * `soData`: a fonte só tem a DATA (publicação, quitação, prazo, vencimento). A tela não inventa
 * hora para esses — mostra só o dia.
 *
 * Todo texto sai daqui CRU (sem HTML): quem desenha usa `textContent`.
 */
final readonly class EventoDaTimelineOutput
{
    public function __construct(
        public string $id,
        public string $tipo,
        public string $categoria,
        public \DateTimeImmutable $quando,
        public bool $soData,
        public string $titulo,
        public ?string $texto = null,
        public ?string $autor = null,
        public ?string $fonte = null,
        public ?string $prioridade = null,
        public bool $pendente = false,
        public ?string $marco = null,
        public bool $editado = false,
        public ?string $link = null,
        public ?string $rotuloLink = null,
    ) {
    }

    public function comMarco(string $marco): self
    {
        return new self(
            id: $this->id,
            tipo: $this->tipo,
            categoria: $this->categoria,
            quando: $this->quando,
            soData: $this->soData,
            titulo: $this->titulo,
            texto: $this->texto,
            autor: $this->autor,
            fonte: $this->fonte,
            prioridade: $this->prioridade,
            pendente: $this->pendente,
            marco: $marco,
            editado: $this->editado,
            link: $this->link,
            rotuloLink: $this->rotuloLink,
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'id'         => $this->id,
            'tipo'       => $this->tipo,
            'categoria'  => $this->categoria,
            'quando'     => $this->quando->format(\DateTimeInterface::ATOM),
            'dia'        => $this->quando->format('Y-m-d'),
            'hora'       => $this->soData ? null : $this->quando->format('H:i'),
            'titulo'     => $this->titulo,
            'texto'      => $this->texto,
            'autor'      => $this->autor,
            'fonte'      => $this->fonte,
            'prioridade' => $this->prioridade,
            'pendente'   => $this->pendente,
            'marco'      => $this->marco,
            'editado'    => $this->editado,
            'link'       => $this->link,
            'rotuloLink' => $this->rotuloLink,
        ];
    }
}
