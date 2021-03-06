<?php
/*
 * Poggit
 *
 * Copyright (C) 2016-2017 Poggit
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace poggit\module\home;

use poggit\builder\ProjectBuilder;
use poggit\embed\EmbedUtils;
use poggit\embed\ProjectThumbnail;
use poggit\module\VarPage;
use poggit\Poggit;
use poggit\timeline\TimeLineEvent;
use poggit\utils\internet\CurlUtils;
use poggit\utils\internet\MysqlUtils;
use poggit\utils\SessionUtils;

class MemberHomePage extends VarPage {

    /** @var array[] */
    private $timeline;

    /** @var array[] */
    private $projects;
    private $recentBuilds;
    private $repos;

    public function __construct() {
        $session = SessionUtils::getInstance();
        $repos = [];
        $ids = [];
        foreach(CurlUtils::ghApiGet("user/repos?per_page=" . Poggit::getCurlPerPage(), $session->getAccessToken()) as $repo) {
            $repos[(int) $repo->id] = $repo;
            $ids[] = "p.repoId=" . (int) $repo->id;
        }

        foreach(count($ids) === 0 ? [] : MysqlUtils::query("SELECT r.repoId AS rid, p.projectId AS pid, p.name AS pname,
                (SELECT COUNT(*) FROM builds WHERE builds.projectId=p.projectId 
                        AND builds.class IS NOT NULL) AS bcnt,
                IFNULL((SELECT CONCAT_WS(',', buildId, internal) FROM builds WHERE builds.projectId = p.projectId
                        AND builds.class = ? ORDER BY created DESC LIMIT 1), 'null') AS bnum
                FROM projects p INNER JOIN repos r ON p.repoId=r.repoId WHERE r.build=1 AND (" .
            implode(" OR ", $ids) . ") ORDER BY r.name, pname", "i", ProjectBuilder::BUILD_CLASS_DEV) as $projRow) {
            $project = new ProjectThumbnail();
            $project->id = (int) $projRow["pid"];
            $project->name = $projRow["pname"];
            $project->buildCount = (int) $projRow["bcnt"];
            if($projRow["bnum"] === "null") {
                $project->latestBuildGlobalId = null;
                $project->latestBuildInternalId = null;
            } else {
                list($project->latestBuildGlobalId, $project->latestBuildInternalId) = array_map("intval", explode(",", $projRow["bnum"]));
            }
            $repo = $repos[(int) $projRow["rid"]];
            $project->repo = $repo;
            $repo->projects[] = $project;
            $this->repos[] = $repo;
        }


        $repoIdClause = implode(",", array_keys($repos));
        $this->timeline = MysqlUtils::query("SELECT e.eventId, UNIX_TIMESTAMP(e.created) AS created, e.type, e.details 
            FROM user_timeline u INNER JOIN event_timeline e ON u.eventId = e.eventId
            WHERE u.userId = ? ORDER BY e.created DESC LIMIT 50", "i", $session->getLogin()["uid"]);
        $this->projects = MysqlUtils::query("SELECT r.repoId, p.projectId, p.name
            FROM projects p INNER JOIN repos r ON p.repoId = r.repoId 
            WHERE r.build = 1 AND p.projectId IN ($repoIdClause)");

        $builds = MysqlUtils::query("SELECT b.buildId, b.internal, b.class, UNIX_TIMESTAMP(b.created) AS created,
            r.owner, r.name AS repoName, p.name AS projectName
            FROM builds b INNER JOIN projects p ON b.projectId = p.projectId INNER JOIN repos r ON p.repoId = r.repoId
            WHERE class = ? AND private = 0 AND r.build > 0 ORDER BY created DESC LIMIT 100", "i", ProjectBuilder::BUILD_CLASS_DEV);
        $latest = [];
        $recentBuilds = [];
        foreach ($builds as $row) {           
            if (!in_array($row["projectName"], $latest)){
            $row["buildId"] = (int) $row["buildId"];
            $row["internal"] = (int) $row["internal"];
            $row["class"] = (int) $row["class"];
            $row["created"] = (int) $row["created"];
            $recentBuilds[] = $row;
            $latest[] = $row["projectName"];
            }
        }
        $this->recentBuilds = $recentBuilds;
    }

    protected function thumbnailProject(ProjectThumbnail $project, $class = "brief-info") {
        ?>
        <div class="<?= $class ?>" data-project-id="<?= $project->id ?>">

            <a href="<?= Poggit::getRootPath() ?>ci/<?= $project->repo->full_name ?>/<?= urlencode($project->name) ?>">
                <?= htmlspecialchars($project->name) ?>
            </a>
            <p class="remark">Total: <?= $project->buildCount ?> development
                build<?= $project->buildCount > 1 ? "s" : "" ?></p>
            <p class="remark">
                Last development build:
                <?php
                if($project->latestBuildInternalId !== null or $project->latestBuildGlobalId !== null) {
                    $url = "ci/" . $project->repo->full_name . "/" . urlencode($project->name) . "/" . $project->latestBuildInternalId;
                    EmbedUtils::showBuildNumbers($project->latestBuildGlobalId, $project->latestBuildInternalId, $url);
                } else {
                    echo "No builds yet";
                }
                ?>
            </p>
        </div>
        <?php
    }

    public function bodyClasses(): array {
        return ["horiz-panes"];
    }

    public function getTitle(): string {
        return "Poggit";
    }

    public function output() {
        ?>
        <div class="memberpanelplugins">
            <div class="recentbuildsheader"><h4>Recent Builds</h4></div>
            <div class="recentbuildswrapper">
                <?php
                foreach($this->recentBuilds as $build) {
                    ?>
                    <div class="brief-info">
                        <a href="<?= Poggit::getRootPath() ?>ci/<?= $build["owner"] ?>/<?= $build["projectName"] ?>/<?= $build["projectName"] ?>/<?= (ProjectBuilder::$BUILD_CLASS_HUMAN[$build["class"]] . ":" ?? "") .  $build["internal"] ?>">
                            <?= htmlspecialchars($build["projectName"]) ?></a>
                        <p class="remark">
                            <span class="remark">(<?= $build["owner"] ?>/<?= $build["repoName"] ?>)</span></p>
                        <p class="remark"><?= ProjectBuilder::$BUILD_CLASS_HUMAN[$build["class"]] ?> Build
                            #<?= $build["internal"] ?></p>
                        <p class="remark">Created <span class="time-elapse" data-timestamp="<?= $build["created"] ?>"> ago</span>
                        </p>
                    </div>
                <?php } ?>
            </div>
        </div>
        <div class="memberpaneltimeline">

            <h1 class="motto">Concentrate on your code.<br/> Leave the dirty work to the machines.</h1>
            <h2 class="submotto">Automatic development builds with lint tailored for
                PocketMine plugins.<br/>
            </h2>
            <p class="submotto">Why does Poggit exist? Simply to stop this situation from the web comic
                <a href="https://xkcd.com/1319"><em>xkcd</em></a> from happening.</p>
            <p class="submotto">
                    <img class="resize" src="res/automationtransp.png"/></p>
            <hr/>
            <h1 class="motto">Build Your Projects</h1>
            <h2 class="submotto">Create builds the moment you push to GitHub.</h2>
            <p>Poggit CI will set up webhooks in your repos to link to Poggit. When you push a commit to your repo,
                Poggit will create a development build. When you receive pull requests, Poggit also creates PR builds,
                so you can test the pull request by downloading a build from Poggit CI directly.</p>
            <p>Different plugin frameworks are supported. Currently, the normal one with a <code
                        class="code">plugin.yml</code>, and the NOWHERE framework, can be used.</p>
            <p>An online language manager can also be enabled. After you push some language files to your repo, there
                will be a webpage for online translator, and other people can help you translate your plugin to other
                languages. Then the poglang library will be compiled with your plugin, along with some language files
                contributed by the community.</p>
            <hr/>
            <h1 class="motto">Lint for PocketMine Plugins</h1>
            <h2 class="submotto">Checks pull request before you can merge them.</h2>
            <p>After Poggit CI creates a build for your project, it will also execute lint on it. Basically, lint is
                something that checks if your code is having problems. See <a
                        href="<?= Poggit::getRootPath() ?>help.lint">Poggit Help: Lint</a> for what the lint checks.
            </p>
            <p>You can check out the lint result on the Poggit Build page. The lint result will also be uploaded to
                GitHub, in the form of status checks, which will do
                <a target="_blank" href="<?= Poggit::getRootPath() ?>ghhst">many cool things</a>.</p>
            <p class="remark">Note: Poggit cannot test the builds for you, but there is a script that you can put into
                your <a href="https://docs.travis-ci.com/user/getting-started/">Travis-CI</a> build, which will wait for
                and then download builds from Poggit for testing.</p>

            <div class="timeline">
                <?php foreach($this->timeline as $event) { ?>
                    <div class="timeline-event">
                        <?php TimeLineEvent::fromJson((int) $event["eventId"], (int) $event["created"], (int) $event["type"], json_decode($event["details"]))->output() ?>
                    </div>
                <?php } ?>
            </div>
        </div>
        <div class="memberpanelprojects">
            <div class="recentbuildsheader"><h4>My projects</h4></div>
            <?php
            if(isset($this->repos)) {
                $i = 0;
                foreach($this->repos as $repo) {
                    if(count($repo->projects) === 0) continue;
                    foreach($repo->projects as $project) {
                        if(++$i > 10) break 2;
                        $this->thumbnailProject($project, "brief-info");
                    }
                }
            }
            ?>
        </div>
        <?php
    }

}
