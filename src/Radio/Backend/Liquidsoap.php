<?php /** @noinspection SummerTimeUnsafeTimeManipulationInspection */

namespace App\Radio\Backend;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Filesystem;
use Azura\EventDispatcher;
use App\Radio\Adapters;
use App\Radio\AutoDJ;
use Doctrine\ORM\EntityManager;
use App\Entity;
use Monolog\Logger;
use Psr\Http\Message\UriInterface;
use Supervisor\Supervisor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Liquidsoap extends AbstractBackend implements EventSubscriberInterface
{
    public const CROSSFADE_DISABLED = 'none';
    public const CROSSFADE_SMART = 'smart';
    public const CROSSFADE_NORMAL = 'normal';

    /** @var AutoDJ */
    protected $autodj;

    /** @var Filesystem */
    protected $filesystem;

    /**
     * @param EntityManager $em
     * @param Supervisor $supervisor
     * @param Logger $logger
     * @param EventDispatcher $dispatcher
     * @param AutoDJ $autodj
     * @param Filesystem $filesystem
     *
     * @see \App\Provider\RadioProvider
     */
    public function __construct(
        EntityManager $em,
        Supervisor $supervisor,
        Logger $logger,
        EventDispatcher $dispatcher,
        AutoDJ $autodj,
        Filesystem $filesystem
    ) {
        parent::__construct($em, $supervisor, $logger, $dispatcher);

        $this->autodj = $autodj;
        $this->filesystem = $filesystem;
    }

    public static function getSubscribedEvents()
    {
        return [
            WriteLiquidsoapConfiguration::NAME => [
                ['writeHeaderFunctions', 30],
                ['writePlaylistConfiguration', 25],
                ['writeHarborConfiguration', 20],
                ['writeCustomConfiguration', 15],
                ['writeMetadataFeedbackConfiguration', 10],
                ['writeLocalBroadcastConfiguration', 5],
                ['writeRemoteBroadcastConfiguration', 0],
            ],
        ];
    }

    /**
     * Write configuration from Station object to the external service.
     *
     * Special thanks to the team of PonyvilleFM for assisting with Liquidsoap configuration and debugging.
     *
     * @param Entity\Station $station
     * @return bool
     */
    public function write(Entity\Station $station): bool
    {
        $event = new WriteLiquidsoapConfiguration($station);
        $this->dispatcher->dispatch(WriteLiquidsoapConfiguration::NAME, $event);

        $ls_config_contents = $event->buildConfiguration();

        $config_path = $station->getRadioConfigDir();
        $ls_config_path = $config_path . '/liquidsoap.liq';

        file_put_contents($ls_config_path, $ls_config_contents);
        return true;
    }

    public function writeHeaderFunctions(WriteLiquidsoapConfiguration $event)
    {
        $event->prependLines([
            '# WARNING! This file is automatically generated by AzuraCast.',
            '# Do not update it directly!',
        ]);

        $station = $event->getStation();
        $config_path = $station->getRadioConfigDir();

        $event->appendLines([
            'set("init.daemon", false)',
            'set("init.daemon.pidfile.path","' . $config_path . '/liquidsoap.pid")',
            'set("log.stdout", true)',
            'set("log.file", false)',
            'set("server.telnet",true)',
            'set("server.telnet.bind_addr","'.(APP_INSIDE_DOCKER ? '0.0.0.0' : '127.0.0.1').'")',
            'set("server.telnet.port", ' . $this->_getTelnetPort($station) . ')',
            'set("harbor.bind_addrs",["0.0.0.0"])',
            '',
            'set("tag.encodings",["UTF-8","ISO-8859-1"])',
            'set("encoder.encoder.export",["artist","title","album","song"])',
            '',
            'setenv("TZ", "'.$this->_cleanUpString($station->getTimezone()).'")',
            '',
        ]);
    }

    public function writePlaylistConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        // Clear out existing playlists directory.
        $playlist_path = $station->getRadioPlaylistsDir();
        $current_playlists = array_diff(scandir($playlist_path, SCANDIR_SORT_NONE), ['..', '.']);
        foreach ($current_playlists as $list) {
            @unlink($playlist_path . '/' . $list);
        }

        // Set up playlists using older format as a fallback.
        $has_default_playlist = false;
        $playlist_objects = [];

        foreach ($station->getPlaylists() as $playlist_raw) {
            /** @var Entity\StationPlaylist $playlist_raw */
            if (!$playlist_raw->getIsEnabled()) {
                continue;
            }
            if ($playlist_raw->getType() === Entity\StationPlaylist::TYPE_DEFAULT) {
                $has_default_playlist = true;
            }

            $playlist_objects[] = $playlist_raw;
        }

        // Create a new default playlist if one doesn't exist.
        if (!$has_default_playlist) {

            $this->logger->info('No default playlist existed for this station; new one was automatically created.', ['station_id' => $station->getId(), 'station_name' => $station->getName()]);

            // Auto-create an empty default playlist.
            $default_playlist = new Entity\StationPlaylist($station);
            $default_playlist->setName('default');

            /** @var EntityManager $em */
            $this->em->persist($default_playlist);
            $this->em->flush();

            $playlist_objects[] = $default_playlist;
        }

        $gen_playlist_weights = [];
        $gen_playlist_vars = [];

        $special_playlists = [
            'once_per_x_songs' => [
                '# Once per x Songs Playlists',
            ],
            'once_per_x_minutes' => [
                '# Once per x Minutes Playlists',
            ],
        ];

        $schedule_switches = [];
        $schedule_switches_interrupting = [];

        foreach ($playlist_objects as $playlist) {
            /** @var Entity\StationPlaylist $playlist */
            $playlist_var_name = 'playlist_' . $playlist->getShortName();

            $uses_random = true;
            $uses_reload_mode = true;
            $uses_conservative = false;

            if ($playlist->backendLoopPlaylistOnce()) {
                $playlist_func_name = 'playlist.once';
            } else if ($playlist->backendMerge()) {
                $playlist_func_name = 'playlist.merge';
                $uses_reload_mode = false;
            } else {
                $playlist_func_name = 'playlist';
                $uses_random = false;
                $uses_conservative = true;
            }

            $playlist_config_lines = [];

            if ($playlist->getSource() === Entity\StationPlaylist::SOURCE_SONGS) {
                $playlist_file_path = $this->writePlaylistFile($playlist, false);

                if (!$playlist_file_path) {
                    continue;
                }

                // Liquidsoap's playlist functions support very different argument patterns. :/
                $playlist_params = [
                    'id="'.$this->_cleanUpString($playlist_var_name).'"',
                ];

                if ($uses_random) {
                    if (Entity\StationPlaylist::ORDER_SEQUENTIAL !== $playlist->getOrder()) {
                        $playlist_params[] = 'random=true';
                    }
                } else {
                    $playlist_modes = [
                        Entity\StationPlaylist::ORDER_SEQUENTIAL    => 'normal',
                        Entity\StationPlaylist::ORDER_SHUFFLE       => 'randomize',
                        Entity\StationPlaylist::ORDER_RANDOM        => 'random',
                    ];

                    $playlist_params[] = 'mode="'.$playlist_modes[$playlist->getOrder()].'"';
                }

                if ($uses_reload_mode) {
                    $playlist_params[] = 'reload_mode="watch"';
                }

                if ($uses_conservative) {
                    $playlist_params[] = 'conservative=true';
                    $playlist_params[] = 'default_duration=10.';
                    $playlist_params[] = 'length=20.';
                }

                $playlist_params[] = '"'.$playlist_file_path.'"';

                $playlist_config_lines[] = $playlist_var_name . ' = '.$playlist_func_name.'('.implode(',', $playlist_params).')';
            } else {
                switch($playlist->getRemoteType())
                {
                    case Entity\StationPlaylist::REMOTE_TYPE_PLAYLIST:
                        $playlist_func = $playlist_func_name.'("'.$this->_cleanUpString($playlist->getRemoteUrl()).'")';
                        $playlist_config_lines[] = $playlist_var_name . ' = '.$playlist_func;
                        break;

                    case Entity\StationPlaylist::REMOTE_TYPE_STREAM:
                    default:
                        $remote_url = $playlist->getRemoteUrl();
                        $remote_url_scheme = parse_url($remote_url, \PHP_URL_SCHEME);
                        $remote_url_function = ('https' === $remote_url_scheme) ? 'input.https' : 'input.http';

                        $buffer = $playlist->getRemoteBuffer();
                        $buffer = ($buffer < 1) ? Entity\StationPlaylist::DEFAULT_REMOTE_BUFFER : $buffer;

                        $playlist_config_lines[] = $playlist_var_name . ' = mksafe('.$remote_url_function.'(max='.$buffer.'., "'.$this->_cleanUpString($remote_url).'"))';
                        break;
                }
            }

            $playlist_config_lines[] = $playlist_var_name . ' = audio_to_stereo(id="stereo_'.$this->_cleanUpString($playlist_var_name).'", '.$playlist_var_name.')';

            if ($playlist->isJingle()) {
                $playlist_config_lines[] = $playlist_var_name . ' = drop_metadata('.$playlist_var_name.')';
            }

            if (Entity\StationPlaylist::TYPE_ADVANCED === $playlist->getType()) {
                $playlist_config_lines[] = 'ignore('.$playlist_var_name.')';
            }

            $event->appendLines($playlist_config_lines);

            if ($playlist->backendPlaySingleTrack()) {
                $playlist_var_name = 'once('.$playlist_var_name.')';
            }

            switch($playlist->getType())
            {
                case Entity\StationPlaylist::TYPE_DEFAULT:
                    $gen_playlist_weights[] = $playlist->getWeight();
                    $gen_playlist_vars[] = $playlist_var_name;
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_X_SONGS:
                    $special_playlists['once_per_x_songs'][] = 'radio = rotate(weights=[1,' . $playlist->getPlayPerSongs() . '], [' . $playlist_var_name . ', radio])';
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_X_MINUTES:
                    $delay_seconds = $playlist->getPlayPerMinutes() * 60;
                    $delay_track_sensitive = $playlist->backendInterruptOtherSongs() ? 'false' : 'true';

                    $special_playlists['once_per_x_minutes'][] = 'radio = fallback(track_sensitive='.$delay_track_sensitive.', [delay(' . $delay_seconds . '., ' . $playlist_var_name . '), radio])';
                    break;

                case Entity\StationPlaylist::TYPE_ONCE_PER_HOUR:
                    $play_time = $playlist->getPlayPerHourMinute().'m';

                    $schedule_timing = '({ ' . $play_time . ' }, ' . $playlist_var_name . ')';
                    if ($playlist->backendInterruptOtherSongs()) {
                        $schedule_switches_interrupting[] = $schedule_timing;
                    } else {
                        $schedule_switches[] = $schedule_timing;
                    }
                    break;

                case Entity\StationPlaylist::TYPE_SCHEDULED:
                    $play_time = $this->_getScheduledPlaylistPlayTime($playlist);

                    $schedule_timing = '({ ' . $play_time . ' }, ' . $playlist_var_name . ')';
                    if ($playlist->backendInterruptOtherSongs()) {
                        $schedule_switches_interrupting[] = $schedule_timing;
                    } else {
                        $schedule_switches[] = $schedule_timing;
                    }
                    break;
            }
        }

        // Build "default" type playlists.
        $event->appendLines([
            '# Standard Playlists',
            'radio = random(id="'.$this->_getVarName('standard_playlists', $station).'", weights=[' . implode(', ', $gen_playlist_weights) . '], [' . implode(', ', $gen_playlist_vars) . '])',
        ]);

        if (!empty($schedule_switches)) {
            $schedule_switches[] = '({true}, radio)';

            $event->appendLines([
                '# Standard Schedule Switches',
                'radio = switch(id="'.$this->_getVarName('schedule_switch', $station).'", track_sensitive=true, [ ' . implode(', ', $schedule_switches) . ' ])',
            ]);
        }
        if (!empty($schedule_switches_interrupting)) {
            $schedule_switches_interrupting[] = '({true}, radio)';

            $event->appendLines([
                '# Interrupting Schedule Switches',
                'radio = switch(id="'.$this->_getVarName('interrupt_switch', $station).'", track_sensitive=false, [ ' . implode(', ', $schedule_switches_interrupting) . ' ])',
            ]);
        }

        // Add in special playlists if necessary.
        foreach($special_playlists as $playlist_type => $playlist_config_lines) {
            if (count($playlist_config_lines) > 1) {
                $event->appendLines($playlist_config_lines);
            }
        }

        $error_file = APP_INSIDE_DOCKER
            ? '/usr/local/share/icecast/web/error.mp3'
            : APP_INCLUDE_ROOT . '/resources/error.mp3';

        $event->appendLines([
            'requests = audio_to_stereo(request.queue(id="'.$this->_getVarName('requests', $station).'"))',
            'radio = fallback(id="'.$this->_getVarName('requests_fallback', $station).'", track_sensitive = true, [requests, radio])',
            '',
            'radio = cue_cut(id="'.$this->_getVarName('radio_cue', $station).'", radio)',
            'add_skip_command(radio)',
            '',
            'radio = fallback(id="'.$this->_getVarName('safe_fallback', $station).'", track_sensitive = false, [radio, single(id="error_jingle", "'.$error_file.'")])',
        ]);
    }

    /**
     * Given a scheduled playlist, return the time criteria that Liquidsoap can use to determine when to play it.
     *
     * @param Entity\StationPlaylist $playlist
     * @return string
     */
    protected function _getScheduledPlaylistPlayTime(Entity\StationPlaylist $playlist): string
    {
        $start_time = $playlist->getScheduleStartTime();
        $end_time = $playlist->getScheduleEndTime();

        // Handle multi-day playlists.
        if ($start_time > $end_time) {
            $play_times = [
                $this->_formatTimeCode($start_time).'-23h59m59s',
                '00h00m-'.$this->_formatTimeCode($end_time),
            ];

            $playlist_schedule_days = $playlist->getScheduleDays();
            if (!empty($playlist_schedule_days) && count($playlist_schedule_days) < 7) {
                $current_play_days = [];
                $next_play_days = [];

                foreach($playlist_schedule_days as $day) {
                    $day = (int)$day;
                    $current_play_days[] = (($day === 7) ? '0' : $day).'w';

                    $day++;
                    if ($day > 7) {
                        $day = 1;
                    }
                    $next_play_days[] = (($day === 7) ? '0' : $day).'w';
                }

                $play_times[0] = '('.implode(' or ', $current_play_days).') and '.$play_times[0];
                $play_times[1] = '('.implode(' or ', $next_play_days).') and '.$play_times[1];
            }

            return '('.implode(') or (', $play_times).')';
        }

        // Handle once-per-day playlists.
        $play_time = ($start_time === $end_time)
            ? $this->_formatTimeCode($start_time)
            : $this->_formatTimeCode($start_time) . '-' . $this->_formatTimeCode($end_time);

        $playlist_schedule_days = $playlist->getScheduleDays();
        if (!empty($playlist_schedule_days) && count($playlist_schedule_days) < 7) {
            $play_days = [];

            foreach($playlist_schedule_days as $day) {
                $day = (int)$day;
                $play_days[] = (($day === 7) ? '0' : $day).'w';
            }

            $play_time = '('.implode(' or ', $play_days).') and '.$play_time;
        }

        return $play_time;
    }

    /**
     * Configure the time offset
     *
     * @param int $time_code
     * @return string
     */
    protected function _formatTimeCode($time_code): string
    {
        $hours = floor($time_code / 100);
        $mins = $time_code % 100;

        return $hours . 'h' . $mins . 'm';
    }

    /**
     * Write a playlist's contents to file so Liquidsoap can process it, and optionally notify
     * Liquidsoap of the change.
     *
     * @param Entity\StationPlaylist $playlist
     * @param bool $notify
     * @return string The full path that was written to.
     */
    public function writePlaylistFile(Entity\StationPlaylist $playlist, $notify = true): ?string
    {
        $station = $playlist->getStation();

        $playlist_path = $station->getRadioPlaylistsDir();
        $playlist_var_name = 'playlist_' . $playlist->getShortName();

        $media_base_dir = $station->getRadioMediaDir().'/';
        $playlist_file = [];
        foreach ($playlist->getMediaItems() as $media_item) {
            /** @var Entity\StationMedia $media_file */
            $media_file = $media_item->getMedia();

            $media_file_path = $media_base_dir.$media_file->getPath();
            $media_annotations = $media_file->getAnnotations();

            if ($playlist->isJingle()) {
                $media_annotations['is_jingle_mode'] = 'true';
                unset($media_annotations['media_id']);
            } else {
                $media_annotations['playlist_id'] = $playlist->getId();
            }

            $annotations_str = [];
            foreach($media_annotations as $annotation_key => $annotation_val) {
                $annotations_str[] = $annotation_key.'="'.$annotation_val.'"';
            }

            $playlist_file[] = 'annotate:'.implode(',', $annotations_str).':'.$media_file_path;
        }

        $playlist_file_path =  $playlist_path . '/' . $playlist_var_name . '.m3u';
        $playlist_file_contents = implode("\n", $playlist_file);

        file_put_contents($playlist_file_path, $playlist_file_contents);

        if ($notify) {
            try {
                $this->command($station, $playlist_var_name.'.reload');
            } catch(\Exception $e) {
                $this->logger->error('Could not reload playlist with AutoDJ.', [
                    'message' => $e->getMessage(),
                    'playlist' => $playlist_var_name,
                    'station' => $station->getId(),
                ]);
            }
        }

        return $playlist_file_path;
    }

    public function writeHarborConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        if (!$station->getEnableStreamers()) {
            return;
        }

        $event->appendLines([
            '# DJ Authentication',
            'def dj_auth(user,password) =',
            '  log("Authenticating DJ: #{user}")',
            '  ret = '.$this->_getApiUrlCommand($station, 'auth', ['dj_user' => 'user', 'dj_password' => 'password']),
            '  log("AzuraCast DJ Auth Response: #{ret}")',
            '  bool_of_string(ret)',
            'end',
            '',
            'live_enabled = ref false',
            '',
            'def live_connected(header) =',
            '  log("DJ Source connected! #{header}")',
            '  live_enabled := true',
            '  ret = '.$this->_getApiUrlCommand($station, 'djon'),
            '  log("AzuraCast Live Connected Response: #{ret}")',
            'end',
            '',
            'def live_disconnected() =',
            '  log("DJ Source disconnected!")',
            '  live_enabled := false',
            '  ret = '.$this->_getApiUrlCommand($station, 'djoff'),
            '  log("AzuraCast Live Disconnected Response: #{ret}")',
            'end',
        ]);

        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';
        $dj_mount = $settings['dj_mount_point'] ?? '/';

        $harbor_params = [
            '"'.$this->_cleanUpString($dj_mount).'"',
            'id="'.$this->_getVarName('input_streamer', $station).'"',
            'port='.$this->getStreamPort($station),
            'user="shoutcast"',
            'auth=dj_auth',
            'icy=true',
            'max=30.',
            'buffer='.((int)($settings['dj_buffer'] ?? 5)).'.',
            'icy_metadata_charset="'.$charset.'"',
            'metadata_charset="'.$charset.'"',
            'on_connect=live_connected',
            'on_disconnect=live_disconnected',
        ];

        $event->appendLines([
            '# A Pre-DJ source of radio that can be broadcasted if needed',
            'radio_without_live = radio',
            'ignore(radio_without_live)',
            '',
            '# Live Broadcasting',
            'live = audio_to_stereo(input.harbor('.implode(', ', $harbor_params).'))',
            'ignore(output.dummy(live, fallible=true))',
            'live = fallback(id="'.$this->_getVarName('live_fallback', $station).'", track_sensitive=false, [live, blank(duration=2.)])',
            '',
            'radio = switch(id="'.$this->_getVarName('live_switch', $station).'", track_sensitive=false, [({!live_enabled}, live), ({true}, radio)])',
        ]);
    }

    public function writeCustomConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();
        $settings = (array)$station->getBackendConfig();

        $event->appendLines([
            '# Allow for Telnet-driven insertion of custom metadata.',
            'radio = server.insert_metadata(id="custom_metadata", radio)',
            '',
            '# Apply amplification metadata (if supplied)',
            'radio = amplify(1., radio)',
        ]);

        // NRJ normalization
        if (true === (bool)($settings['nrj'] ?? false)) {
            $event->appendLines([
                '# Normalization and Compression',
                'radio = normalize(target = 0., window = 0.03, gain_min = -16., gain_max = 0., radio)',
                'radio = compress.exponential(radio, mu = 1.0)',
            ]);
        }

        // Replaygain metadata
        if (true === (bool)($settings['enable_replaygain_metadata'] ?? false)) {
            $event->appendLines([
                '# Replaygain Metadata',
                'enable_replaygain_metadata()',
            ]);
        }

        // Crossfading
        $crossfade_type = $settings['crossfade_type'] ?? self::CROSSFADE_NORMAL;
        $crossfade = round($settings['crossfade'] ?? 2, 1);

        if (self::CROSSFADE_DISABLED !== $crossfade_type && $crossfade > 0) {
            $start_next = round($crossfade * 1.5, 2);

            if (self::CROSSFADE_SMART === $crossfade_type) {
                $crossfade_function = 'smart_crossfade';
            } else {
                $crossfade_function = 'crossfade';
            }

            $event->appendLines([
                'radio = '.$crossfade_function.'(start_next=' . self::toFloat($start_next) . ',fade_out=' . self::toFloat($crossfade) . ',fade_in=' . self::toFloat($crossfade) . ',radio)',
            ]);
        }

        // Custom configuration
        if (!empty($settings['custom_config'])) {
            $event->appendLines([
                '# Custom Configuration (Specified in Station Profile)',
                $settings['custom_config'],
            ]);
        }
    }

    public function writeMetadataFeedbackConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        $event->appendLines([
            '# Send metadata changes back to AzuraCast',
            'def metadata_updated(m) =',
            '  if (m["song_id"] != "") then',
            '    ret = '.$this->_getApiUrlCommand($station, 'feedback', ['song' => 'm["song_id"]', 'media' => 'm["media_id"]', 'playlist' => 'm["playlist_id"]']),
            '    log("AzuraCast Feedback Response: #{ret}")',
            '  end',
            'end',
            '',
            'radio = on_metadata(metadata_updated,radio)'
        ]);
    }

    public function writeLocalBroadcastConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        if (Adapters::FRONTEND_REMOTE === $station->getFrontendType()) {
            return;
        }

        $ls_config = [
            '# Local Broadcasts',
        ];

        // Configure the outbound broadcast.
        $i = 0;
        foreach ($station->getMounts() as $mount_row) {
            $i++;

            /** @var Entity\StationMount $mount_row */
            if (!$mount_row->getEnableAutodj()) {
                continue;
            }

            $ls_config[] = $this->_getOutputString($station, $mount_row, 'local_'.$i);
        }

        $event->appendLines($ls_config);
    }

    public function writeRemoteBroadcastConfiguration(WriteLiquidsoapConfiguration $event)
    {
        $station = $event->getStation();

        $ls_config = [
            '# Remote Relays',
        ];

        // Set up broadcast to remote relays.
        $i = 0;
        foreach($station->getRemotes() as $remote_row) {
            $i++;

            /** @var Entity\StationRemote $remote_row */
            if (!$remote_row->getEnableAutodj()) {
                continue;
            }

            $ls_config[] = $this->_getOutputString($station, $remote_row, 'relay_'.$i);
        }

        $event->appendLines($ls_config);
    }

    /**
     * Returns the URL that LiquidSoap should call when attempting to execute AzuraCast API commands.
     *
     * @param Entity\Station $station
     * @param string $endpoint
     * @param array $params
     * @return string
     */
    protected function _getApiUrlCommand(Entity\Station $station, $endpoint, $params = []): string
    {
        // Docker cURL-based API URL call with API authentication.
        if (APP_INSIDE_DOCKER) {
            $params = (array)$params;
            $params['api_auth'] = '"'.$station->getAdapterApiKey().'"';

            $service_uri = (APP_DOCKER_REVISION >= 5) ? 'web' : 'nginx';
            $api_url = 'http://' . $service_uri . '/api/internal/' . $station->getId() . '/' . $endpoint;
            $command = 'curl -s --request POST --url ' . $api_url;
            foreach ($params as $param_key => $param_val) {
                $command .= ' --form ' . $param_key . '="^quote(' . $param_val . ')^"';
            }
        } else {
            // Ansible shell-script call.
            $shell_path = '/usr/bin/php '.APP_INCLUDE_ROOT.'/bin/azuracast';

            $shell_args = [];
            $shell_args[] = 'azuracast:internal:'.$endpoint;
            $shell_args[] = $station->getId();

            foreach((array)$params as $param_key => $param_val) {
                $shell_args [] = '--'.$param_key.'="^quote('.$param_val.')^"';
            }

            $command = $shell_path.' '.implode(' ', $shell_args);
        }

        return 'list.hd(get_process_lines("'.$command.'"), default="")';
    }

    /**
     * Filter a user-supplied string to be a valid LiquidSoap config entry.
     *
     * @param string $string
     * @return mixed
     */
    protected function _cleanUpString($string)
    {
        return str_replace(['"', "\n", "\r"], ['\'', '', ''], $string);
    }

    /**
     * Given an original name and a station, return a filtered prefixed variable identifying the station.
     *
     * @param string $original_name
     * @param Entity\Station $station
     * @return string
     */
    protected function _getVarName($original_name, Entity\Station $station): string
    {
        $short_name = $this->_cleanUpString($station->getShortName());

        return (!empty($short_name))
            ? $short_name.'_'.$original_name
            : 'station_'.$station->getId().'_'.$original_name;
    }

    /**
     * Given outbound broadcast information, produce a suitable LiquidSoap configuration line for the stream.
     *
     * @param Entity\Station $station
     * @param Entity\StationMountInterface $mount
     * @param string $id
     * @return string
     */
    protected function _getOutputString(Entity\Station $station, Entity\StationMountInterface $mount, $id = '')
    {
        $settings = (array)$station->getBackendConfig();
        $charset = $settings['charset'] ?? 'UTF-8';

        $bitrate = (int)($mount->getAutodjBitrate() ?? 128);

        switch(strtolower($mount->getAutodjFormat()))
        {
            case $mount::FORMAT_AAC:
                $afterburner = ($bitrate >= 160) ? 'true' : 'false';
                $aot = ($bitrate >= 96) ? 'mpeg4_aac_lc' : 'mpeg4_he_aac_v2';

                $output_format = '%fdkaac(channels=2, samplerate=44100, bitrate='.$bitrate.', afterburner='.$afterburner.', aot="'.$aot.'", sbr_mode=true)';
                break;

            case $mount::FORMAT_OGG:
                $output_format = '%vorbis.cbr(samplerate=44100, channels=2, bitrate=' . $bitrate . ')';
                break;

            case $mount::FORMAT_OPUS:
                $output_format = '%opus(samplerate=48000, bitrate='.$bitrate.', vbr="none", application="audio", channels=2, signal="music", complexity=10, max_bandwidth="full_band")';
                break;

            case $mount::FORMAT_MP3:
            default:
                $output_format = '%mp3(samplerate=44100, stereo=true, bitrate=' . $bitrate . ', id3v2=true)';
                break;
        }

        $output_params = [];
        $output_params[] = $output_format;
        $output_params[] = 'id="'.$this->_getVarName($id, $station).'"';

        $output_params[] = 'host = "'.$this->_cleanUpString($mount->getAutodjHost()).'"';
        $output_params[] = 'port = ' . (int)$mount->getAutodjPort();

        $username = $mount->getAutodjUsername();
        if (!empty($username)) {
            $output_params[] = 'user = "'.$this->_cleanUpString($username).'"';
        }

        $output_params[] = 'password = "'.$this->_cleanUpString($mount->getAutodjPassword()).'"';

        if (!empty($mount->getAutodjMount())) {
            $output_params[] = 'mount = "'.$this->_cleanUpString($mount->getAutodjMount()).'"';
        }

        $output_params[] = 'name = "' . $this->_cleanUpString($station->getName()) . '"';
        $output_params[] = 'description = "' . $this->_cleanUpString($station->getDescription()) . '"';
        $output_params[] = 'genre = "'.$this->_cleanUpString($station->getGenre()).'"';

        if (!empty($station->getUrl())) {
            $output_params[] = 'url = "' . $this->_cleanUpString($station->getUrl()) . '"';
        }

        $output_params[] = 'public = '.($mount->getIsPublic() ? 'true' : 'false');
        $output_params[] = 'encoding = "'.$charset.'"';

        if ($mount->getAutodjShoutcastMode()) {
            $output_params[] = 'protocol="icy"';
        }

        $output_params[] = 'radio';

        return 'output.icecast(' . implode(', ', $output_params) . ')';
    }

    /**
     * @inheritdoc
     */
    public function getCommand(Entity\Station $station): ?string
    {
        if ($binary = self::getBinary()) {
            $config_path = $station->getRadioConfigDir() . '/liquidsoap.liq';
            return $binary . ' ' . $config_path;
        }

        return '/bin/false';
    }

    /**
     * If a station uses Manual AutoDJ mode, enqueue a request directly with Liquidsoap.
     *
     * @param Entity\Station $station
     * @param string $music_file
     * @return array
     */
    public function request(Entity\Station $station, $music_file): array
    {
        $requests_var = $this->_getVarName('requests', $station);

        $queue = $this->command($station, $requests_var.'.queue');

        if (!empty($queue[0])) {
            throw new \Exception('Song(s) still pending in request queue.');
        }

        return $this->command($station, $requests_var.'.push ' . $music_file);
    }

    /**
     * Tell LiquidSoap to skip the currently playing song.
     *
     * @param Entity\Station $station
     * @return array
     */
    public function skip(Entity\Station $station): array
    {
        return $this->command(
            $station,
            $this->_getVarName('radio_cue', $station).'.skip'
        );
    }

    /**
     * Tell LiquidSoap to disconnect the current live streamer.
     *
     * @param Entity\Station $station
     * @return array
     */
    public function disconnectStreamer(Entity\Station $station): array
    {
        $current_streamer = $station->getCurrentStreamer();
        $disconnect_timeout = (int)$station->getDisconnectDeactivateStreamer();

        if ($current_streamer instanceof Entity\StationStreamer && $disconnect_timeout > 0) {
            $current_streamer->deactivateFor($disconnect_timeout);

            $this->em->persist($current_streamer);
            $this->em->flush();
        }

        return $this->command(
            $station,
            $this->_getVarName('input_streamer', $station).'.stop'
        );
    }

    /**
     * Execute the specified remote command on LiquidSoap via the telnet API.
     *
     * @param Entity\Station $station
     * @param string $command_str
     * @return array
     * @throws \Azura\Exception
     */
    public function command(Entity\Station $station, $command_str)
    {
        $fp = stream_socket_client('tcp://'.(APP_INSIDE_DOCKER ? 'stations' : 'localhost').':' . $this->_getTelnetPort($station), $errno, $errstr, 20);

        if (!$fp) {
            throw new \Azura\Exception('Telnet failure: ' . $errstr . ' (' . $errno . ')');
        }

        fwrite($fp, str_replace(["\\'", '&amp;'], ["'", '&'], urldecode($command_str)) . "\nquit\n");

        $response = [];
        while (!feof($fp)) {
            $response[] = trim(fgets($fp, 1024));
        }

        fclose($fp);

        return $response;
    }

    /**
     * Returns the port used for DJs/Streamers to connect to LiquidSoap for broadcasting.
     *
     * @param Entity\Station $station
     * @return int The port number to use for this station.
     */
    public function getStreamPort(Entity\Station $station): int
    {
        $settings = (array)$station->getBackendConfig();

        if (!empty($settings['dj_port'])) {
            return (int)$settings['dj_port'];
        }

        // Default to frontend port + 5
        $frontend_config = (array)$station->getFrontendConfig();
        $frontend_port = $frontend_config['port'] ?? (8000 + (($station->getId() - 1) * 10));

        return $frontend_port + 5;
    }

    /**
     * Returns the internal port used to relay requests and other changes from AzuraCast to LiquidSoap.
     *
     * @param Entity\Station $station
     * @return int The port number to use for this station.
     */
    protected function _getTelnetPort(Entity\Station $station): int
    {
        $settings = (array)$station->getBackendConfig();
        return (int)($settings['telnet_port'] ?? ($this->getStreamPort($station) - 1));
    }

    /*
     * INTERNAL LIQUIDSOAP COMMANDS
     */

    public function authenticateStreamer(Entity\Station $station, $user, $pass): string
    {
        // Allow connections using the exact broadcast source password.
        $fe_config = (array)$station->getFrontendConfig();
        if (!empty($fe_config['source_pw']) && strcmp($fe_config['source_pw'], $pass) === 0) {
            return 'true';
        }

        // Handle login conditions where the username and password are joined in the password field.
        if (strpos($pass, ',') !== false) {
            [$user, $pass] = explode(',', $pass);
        }
        if (strpos($pass, ':') !== false) {
            [$user, $pass] = explode(':', $pass);
        }

        /** @var Entity\Repository\StationStreamerRepository $streamer_repo */
        $streamer_repo = $this->em->getRepository(Entity\StationStreamer::class);

        $streamer = $streamer_repo->authenticate($station, $user, $pass);

        if ($streamer instanceof Entity\StationStreamer) {
            $this->logger->debug('DJ successfully authenticated.', ['username' => $user]);

            try {
                // Successful authentication: update current streamer on station.
                $station->setCurrentStreamer($streamer);
                $this->em->persist($station);
                $this->em->flush();
            } catch(\Exception $e) {
                $this->logger->error('Error when calling post-DJ-authentication functions.', [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                ]);
            }

            return 'true';
        }

        return 'false';
    }

    public function toggleLiveStatus(Entity\Station $station, $is_streamer_live = true): void
    {
        $station->setIsStreamerLive($is_streamer_live);

        $this->em->persist($station);
        $this->em->flush();
    }

    public function getWebStreamingUrl(Entity\Station $station, UriInterface $base_url): UriInterface
    {
        $stream_port = $this->getStreamPort($station);

        return $base_url
            ->withScheme('wss')
            ->withPath($base_url->getPath().'/radio/' . $stream_port . '/');
    }

    /**
     * Convert an integer or float into a Liquidsoap configuration compatible float.
     *
     * @param float $number
     * @param int $decimals
     * @return string
     */
    public static function toFloat($number, $decimals = 2): string
    {
        if ((int)$number == $number) {
            return (int)$number.'.';
        }

        return number_format($number, $decimals, '.', '');
    }

    /**
     * @inheritdoc
     */
    public static function getBinary()
    {
        // Docker revisions 3 and later use the `radio` container.
        if (APP_INSIDE_DOCKER && APP_DOCKER_REVISION < 3) {
            return '/var/azuracast/.opam/system/bin/liquidsoap';
        }

        return '/usr/local/bin/liquidsoap';
    }
}
