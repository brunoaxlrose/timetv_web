<?php

namespace Application\Helper;

class MessageHelper {
    // Auth Messages
    public const AUTH_REQUIRED = 'Não autorizado.';
    public const METHOD_INVALID = 'Método inválido.';
    public const LOGIN_SUCCESS = 'Login efetuado com sucesso!';
    public const REGISTER_SUCCESS = 'Conta criada com sucesso!';
    public const LOGOUT_SUCCESS = 'Logout efetuado com sucesso!';

    // Collection Tracking Messages
    public const ITEM_INVALID = 'Item ID inválido.';
    public const TRACK_ADD_SUCCESS = 'Adicionado à sua coleção com sucesso!';
    public const TRACK_REMOVE_SUCCESS = 'Removido da sua coleção.';
    public const REWATCH_START_SUCCESS = 'Série reiniciada para reassistir!';

    // Episode Tracking Messages
    public const EPISODE_WATCHED = 'Episódio marcado como assistido.';
    public const EPISODE_UNWATCHED = 'Episódio desmarcado.';
    public const ALL_EPISODES_WATCHED = 'Toda a série foi marcada como vista!';
    public const ALL_EPISODES_RESET = 'Progresso de episódios reiniciado.';
    public const SEASON_WATCHED = 'Temporada marcada como vista!';
    public const SEASON_RESET = 'Temporada desmarcada.';
}
